<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AppConstants;
use App\Tests\Support\PasskeyTestHelper;
use OTPHP\TOTP;
use Override;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole flow through the real HTTP kernel, with real cryptography.
 *
 * Nothing about the ceremony is stubbed: the registration builds a genuine CBOR
 * attestation object signed by a real P-256 key, and the login signs a real
 * assertion. So a pass here means the feature works, not that our mocks agree
 * with our code. What *is* simulated is only the browser's plumbing — the
 * `fetch()` calls become requests, which is exactly the seam worth testing.
 *
 * Passkeys are off in `.env.test` (most of the suite expects today's behaviour),
 * so this test turns them on for itself.
 */
final class PasskeyFlowTest extends WebTestCase
{
    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    private const string IDENTITY = 'lyra';

    /** The auth subdomain, which is also the only allowed ceremony origin. */
    private const string AUTH_HOST = 'auth.example.com';

    /** The RP ID: the base domain, so credentials are shared across it. */
    private const string RP_ID = 'example.com';

    private const string ORIGIN = 'https://auth.example.com';

    private const string AUTH_COOKIE = '__Http-Domain-Preauth';

    private ?PasskeyTestHelper $helper = null;

    /** One kernel per test, as WebTestCase requires. */
    private ?KernelBrowser $client = null;

    private ?string $credentialId = null;

    /** @var array<string,string> */
    private static array $passkeyEnv = [
        'PASSKEY_ENABLED' => '1',
        'SUBDOMAIN_REDIRECT' => '1',
        'AUTH_SUBDOMAIN' => self::AUTH_HOST,
    ];

    /**
     * Turn passkeys on for this test only.
     *
     * Env placeholders resolve at runtime, so setting these before the kernel
     * boots is enough and no separate cache directory is needed.
     */
    private function createPasskeyClient(): KernelBrowser
    {
        foreach (self::$passkeyEnv as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        /* WebTestCase allows exactly one kernel per test, so a test needing a
         * second "visitor" gets this browser with a cleared cookie jar rather
         * than a new kernel. */
        if (null === $this->client) {
            $this->client = static::createClient();
            $this->client->disableReboot();
        }

        return $this->client;
    }

    /**
     * The same kernel, with no cookies — a fresh visitor.
     *
     * Needed because a granted session makes AcceptListener short-circuit at 200
     * before any ceremony listener runs, so a test that registers first and then
     * wants to exercise a ceremony must not carry that cookie.
     */
    private function freshVisitor(): KernelBrowser
    {
        $client = $this->createPasskeyClient();
        $client->getCookieJar()->clear();

        return $client;
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (array_keys(self::$passkeyEnv) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    private function helper(): PasskeyTestHelper
    {
        return $this->helper ??= new PasskeyTestHelper();
    }

    private function credentialId(): string
    {
        return $this->credentialId ??= $this->helper()->credentialId();
    }

    private function validTotpCode(): string
    {
        return TOTP::createFromSecret(self::TOTP_SECRET)->now();
    }

    /**
     * The nonce issued with the login page, which the form must echo back.
     */
    private function nonceFrom(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', self::ORIGIN.'/');

        return (string) $crawler->filter('input[name="nonce"]')->attr('value');
    }

    /**
     * Step 1+2 of registration: submit the form with the checkbox ticked.
     *
     * @return array{publicKey: array<string,mixed>, ceremonyId: string}
     */
    private function beginRegistration(KernelBrowser $client, string $nonce, string $totp = ''): array
    {
        $client->request('GET', self::ORIGIN.'/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => self::IDENTITY,
                'token' => '' === $totp ? $this->validTotpCode() : $totp,
                'nonce' => $nonce,
                'register' => 'passkey',
                'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertArrayHasKey('register', $content);

        return $content['register'];
    }

    /**
     * An ordinary code login, returning the cookie it sets.
     *
     * Used to prove both login paths agree on the cookie; the passkey flow is
     * otherwise easy to break in a way that only shows up in a browser.
     */
    private function codeLoginCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        $client = $this->freshVisitor();
        $nonce = $this->nonceFrom($client);

        $client->request('GET', self::ORIGIN.'/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => self::IDENTITY,
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        return $this->authCookieFrom($response);
    }

    /**
     * base64url-encode a payload, matching the client-side script.
     *
     * @param array<string,mixed> $data
     */
    private function encodePayload(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }

    /**
     * Step 3 of registration: send the attestation the authenticator produced.
     */
    private function finishRegistration(KernelBrowser $client, string $ceremonyId, string $challenge): Response
    {
        $credential = $this->helper()->registrationCredential(
            self::RP_ID,
            $challenge,
            self::ORIGIN,
            $this->credentialId(),
        );

        $client->request(
            'POST',
            self::ORIGIN.'/',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Preauth-Passkey' => 'register-finish',
            ],
            (string) json_encode(['ceremonyId' => $ceremonyId, 'credential' => $credential]),
        );

        return $client->getResponse();
    }

    /* ── registration ─────────────────────────────────────────────────── */

    /**
     * The headline end-to-end property: a real registration is accepted and
     * grants a session.
     */
    public function test_a_real_registration_grants_a_session(): void
    {
        $client = $this->createPasskeyClient();
        $nonce = $this->nonceFrom($client);

        $started = $this->beginRegistration($client, $nonce);
        self::assertArrayHasKey('ceremonyId', $started);
        self::assertSame(self::RP_ID, $started['publicKey']['rp']['id']);

        $response = $this->finishRegistration(
            $client,
            $started['ceremonyId'],
            Base64UrlSafe::decodeNoPadding($started['publicKey']['challenge']),
        );

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        /* the session cookie is domain-scoped so every subdomain accepts it */
        $cookie = $this->authCookieFrom($response);
        self::assertSame(self::AUTH_COOKIE, $cookie->getName());
        self::assertSame(self::RP_ID, $cookie->getDomain());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
    }

    /**
     * Registration is authorised by the TOTP code, so a bad code must not start
     * a ceremony — and must not leave one behind to be finished later.
     */
    public function test_registration_with_a_bad_code_starts_no_ceremony(): void
    {
        $client = $this->createPasskeyClient();
        $nonce = $this->nonceFrom($client);

        $client->request('GET', self::ORIGIN.'/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => self::IDENTITY,
                'token' => '000000',
                'nonce' => $nonce,
                'register' => 'passkey',
                'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertArrayNotHasKey('register', $content);
    }

    /**
     * A spent nonce must be refused even with a valid code, or the ceremony
     * hand-off would be replayable.
     */
    public function test_registration_with_a_spent_nonce_starts_no_ceremony(): void
    {
        $client = $this->createPasskeyClient();
        $nonce = $this->nonceFrom($client);

        /* spend the nonce with a first successful login */
        $client->request('GET', self::ORIGIN.'/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => self::IDENTITY,
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json' => true,
            ]),
        ]);
        self::assertSame(Response::HTTP_SEE_OTHER, $client->getResponse()->getStatusCode());

        /* now try to reuse it for registration */
        $reuse = $this->freshVisitor();
        $reuse->request('GET', self::ORIGIN.'/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => self::IDENTITY,
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'register' => 'passkey',
                'json' => true,
            ]),
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $reuse->getResponse()->getStatusCode());
    }

    /* ── login ────────────────────────────────────────────────────────── */

    /**
     * The other half of the story: register once, then log in with the passkey
     * instead of a code — through the real validator, with a real signature.
     */
    public function test_a_real_passkey_login_grants_a_session(): void
    {
        $client = $this->createPasskeyClient();

        /* register first */
        $started = $this->beginRegistration($client, $this->nonceFrom($client));
        $registered = $this->finishRegistration(
            $client,
            $started['ceremonyId'],
            Base64UrlSafe::decodeNoPadding($started['publicKey']['challenge']),
        );
        self::assertSame(Response::HTTP_SEE_OTHER, $registered->getStatusCode());

        /* the stored record's counter is the one the registration used, and the
         * lenient policy accepts an equal or greater value */
        $counter = $this->helper()->counter() + 1;

        /* drop the cookie the registration granted, or AcceptListener would
         * answer before the ceremony listener is reached */
        $client = $this->freshVisitor();
        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-begin',
        ], '{}');

        $begin = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($begin);
        self::assertSame(self::RP_ID, $begin['publicKey']['rpId']);

        /* the registered credential is offered to the authenticator */
        self::assertNotEmpty($begin['publicKey']['allowCredentials']);

        $challenge = Base64UrlSafe::decodeNoPadding($begin['publicKey']['challenge']);
        $assertion = $this->helper()->assertionCredential(
            self::RP_ID,
            $challenge,
            self::ORIGIN,
            $this->credentialId(),
            $counter,
            hash('sha256', self::IDENTITY, true),
        );

        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-finish',
        ], (string) json_encode(['ceremonyId' => $begin['ceremonyId'], 'credential' => $assertion]));

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode(), (string) $response->getContent());

        /* the Remote-User header proves which identity was authenticated */
        self::assertSame(self::IDENTITY, $response->headers->get('Remote-User'));
        self::assertSame(self::AUTH_COOKIE, $this->authCookieFrom($response)->getName());
    }

    /**
     * A replayed ceremony must fail: the challenge is consumed on first use, so
     * an observed `finish` cannot be re-sent.
     */
    public function test_a_replayed_ceremony_fails(): void
    {
        $client = $this->createPasskeyClient();

        $started = $this->beginRegistration($client, $this->nonceFrom($client));
        $challenge = Base64UrlSafe::decodeNoPadding($started['publicKey']['challenge']);

        $first = $this->finishRegistration($client, $started['ceremonyId'], $challenge);
        self::assertSame(Response::HTTP_SEE_OTHER, $first->getStatusCode());

        /* a replay comes from someone who does not hold the session the first
         * attempt just created, so the cookie must go — otherwise AcceptListener
         * answers 200 and the ceremony listener never sees the replay */
        $replay = $this->finishRegistration($this->freshVisitor(), $started['ceremonyId'], $challenge);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $replay->getStatusCode());
    }

    /**
     * An assertion signed over a different challenge must be refused, which is
     * what binds a login to this session rather than to any past one.
     */
    public function test_an_assertion_for_another_challenge_fails(): void
    {
        $client = $this->createPasskeyClient();

        $started = $this->beginRegistration($client, $this->nonceFrom($client));
        $registered = $this->finishRegistration(
            $client,
            $started['ceremonyId'],
            Base64UrlSafe::decodeNoPadding($started['publicKey']['challenge']),
        );
        /* the stored record's counter is the one the registration used, and the
         * lenient policy accepts an equal or greater value */
        $counter = $this->helper()->counter() + 1;

        $client = $this->freshVisitor();
        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-begin',
        ], '{}');
        $begin = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($begin);

        /* sign a challenge the server never issued */
        $assertion = $this->helper()->assertionCredential(
            self::RP_ID,
            random_bytes(32),
            self::ORIGIN,
            $this->credentialId(),
            $counter,
            hash('sha256', self::IDENTITY, true),
        );

        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-finish',
        ], (string) json_encode(['ceremonyId' => $begin['ceremonyId'], 'credential' => $assertion]));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * An unknown credential must fail with the same generic message a wrong code
     * gets, so the endpoint cannot be used to enumerate live credentials.
     */
    public function test_an_unknown_credential_fails_generically(): void
    {
        $client = $this->createPasskeyClient();

        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-begin',
        ], '{}');
        $begin = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($begin);

        /* a credential nobody registered, signed correctly against this challenge */
        $assertion = $this->helper()->assertionCredential(
            self::RP_ID,
            Base64UrlSafe::decodeNoPadding($begin['publicKey']['challenge']),
            self::ORIGIN,
            random_bytes(16),
            1,
            hash('sha256', 'nobody', true),
        );

        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-finish',
        ], (string) json_encode(['ceremonyId' => $begin['ceremonyId'], 'credential' => $assertion]));

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        /* and the wording matches the ordinary failure, with no hint that the
         * credential was unknown */
        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertSame('Unsuccessful login attempt', $content['message']);
    }

    /* ── the shared session (why SessionIssuer exists) ────────────────── */

    /**
     * Both login paths must produce the *same* cookie, or a user would appear
     * logged in on the auth subdomain but not on the protected one.
     */
    public function test_a_passkey_login_sets_the_same_cookie_as_a_code_login(): void
    {
        /* a code login, for comparison — via the same AJAX path the passkey
         * script uses, so any difference is in the cookie and nothing else */
        $codeCookie = $this->codeLoginCookie();

        /* Now the passkey path, on a visitor with no session: the code login
         * above set a cookie, and with it the login page is replaced by
         * AcceptListener's "already authenticated" reply. */
        $client = $this->freshVisitor();
        $started = $this->beginRegistration($client, $this->nonceFrom($client));
        $registered = $this->finishRegistration(
            $client,
            $started['ceremonyId'],
            Base64UrlSafe::decodeNoPadding($started['publicKey']['challenge']),
        );
        $passkeyCookie = $this->authCookieFrom($registered);

        self::assertSame($codeCookie->getName(), $passkeyCookie->getName());
        self::assertSame($codeCookie->getDomain(), $passkeyCookie->getDomain());
        self::assertSame($codeCookie->getPath(), $passkeyCookie->getPath());
        self::assertSame($codeCookie->isSecure(), $passkeyCookie->isSecure());
        self::assertSame($codeCookie->isHttpOnly(), $passkeyCookie->isHttpOnly());
        self::assertSame($codeCookie->getSameSite(), $passkeyCookie->getSameSite());
    }

    /* ── caching and availability ─────────────────────────────────────── */

    /**
     * A ceremony reply is a browser-facing 2xx, which nothing else in this app
     * produces, so it must carry the full no-store set or a browser could
     * replay a stale challenge.
     */
    public function test_ceremony_responses_are_not_cacheable(): void
    {
        $client = $this->createPasskeyClient();

        $client->request('POST', self::ORIGIN.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-begin',
        ], '{}');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));

        /* the internal marker must not leak to the browser */
        self::assertFalse($response->headers->has(AppConstants::PASSKEY_CEREMONY_MARKER));
    }

    /**
     * With the feature off the listener must be inert, so a caller cannot even
     * obtain a challenge. This uses the default test environment, where
     * PASSKEY_ENABLED is 0.
     */
    public function test_the_ceremony_is_inert_when_passkeys_are_disabled(): void
    {
        /* no passkey env set, so PASSKEY_ENABLED keeps its .env.test value of 0 */
        $client = static::createClient();

        $client->request('POST', 'https://'.self::AUTH_HOST.'/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Preauth-Passkey' => 'login-begin',
        ], '{}');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        /* however the listener answered, the caller must not have been given a
         * challenge — that is the property under test */
        self::assertStringNotContainsString('publicKey', (string) $response->getContent());
    }

    /**
     * The login page must not offer what the server refuses, and vice versa.
     */
    public function test_the_login_page_offers_passkeys_when_enabled(): void
    {
        $client = $this->createPasskeyClient();
        $client->request('GET', self::ORIGIN.'/');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="preauth-passkey"', $html);
        self::assertStringContainsString('id="preauth-register"', $html);
    }

    /**
     * The other half: with the feature off the page must not offer anything.
     * Kept as its own test because a kernel may only be booted once, so the two
     * configurations cannot be compared within a single test.
     */
    public function test_the_login_page_offers_nothing_when_passkeys_are_disabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertStringNotContainsString('preauth-passkey', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('preauth-register', (string) $client->getResponse()->getContent());
    }

    /**
     * The CSP must permit the two WebAuthn directives, because they do not fall
     * back to `default-src` and the browser refuses the ceremony without them.
     */
    public function test_the_csp_permits_the_ceremony_when_passkeys_are_enabled(): void
    {
        $client = $this->createPasskeyClient();
        $client->request('GET', self::ORIGIN.'/');

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("publickey-credentials-get 'self'", $csp);
        self::assertStringContainsString("publickey-credentials-create 'self'", $csp);
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    private function authCookieFrom(Response $response): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (self::AUTH_COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('Expected an auth cookie in the response.');
    }
}
