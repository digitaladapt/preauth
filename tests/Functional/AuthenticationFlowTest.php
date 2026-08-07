<?php
declare(strict_types=1);

namespace App\Tests\Functional;

use App\Data\Payload;
use App\Enum\Scope;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end functional tests exercising the full HTTP kernel: the request
 * travels through RejectListener -> LoginListener -> AllowListener ->
 * AcceptListener -> InterceptListener and the services they orchestrate.
 */
final class AuthenticationFlowTest extends WebTestCase {

    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';
    private const string COOKIE_NAME = '__Host-Http-Preauth';

    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = parent::createClient($options, $server);
        // The app stores nonces in the (in-memory) nonceCache pool.  In
        // production APCu keeps them across requests, but KernelBrowser
        // reboots the kernel between requests by default which would lose
        // them.  Disable the reboot so the nonce issued on the login-page
        // request survives to the login-submission request.
        $client->disableReboot();

        return $client;
    }

    private function validTotpCode(): string {
        // the app uses the real system clock, so generate the code for now()
        return TOTP::createFromSecret(self::TOTP_SECRET)->now();
    }

    /** base64url-encode a payload, matching the client-side JS / X-Preauth header. */
    private function encodePayload(array $data): string {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function loginPayload(
        string $id = 'testuser',
        ?string $token = null,
        string $nonce = 'test-nonce-abc',
        bool $json = true,
    ): string {
        return $this->encodePayload([
            'id'    => $id,
            'token' => $token ?? $this->validTotpCode(),
            'nonce' => $nonce,
            'json'  => $json,
        ]);
    }

    /* ── unauthenticated access ──────────────────────────────────────── */

    public function testUnauthenticatedRequestShowsLoginPage(): void {
        $client = static::createClient();
        $client->request('GET', '/');

        // login page is served with 401 (Unauthorized) to signal the proxy
        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSelectorExists('form#preauth-form');
        self::assertSelectorExists('input[name="nonce"]');
        self::assertSelectorExists('input[name="username"]');
        self::assertSelectorExists('input[name="totp"]');
    }

    public function testLoginPageContainsGeneratedNonce(): void {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $nonceInput = $crawler->filter('input[name="nonce"]')->attr('value');
        self::assertNotEmpty($nonceInput);
        // base64url charset
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonceInput);
    }

    public function testLoginFormDoesNotUsePostMethodWithoutAuthSubdomain(): void {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->filter('form#preauth-form');
        // without central auth, the form should NOT have method="post"
        $method = $form->attr('method');
        self::assertNull($method);
    }

    /* ── successful TOTP login ────────────────────────────────────────── */

    public function testSuccessfulTotpLoginViaHeaderSetsCookieAndRedirects(): void {
        $client = static::createClient();

        // first, grab a valid nonce from the login page
        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');
        self::assertNotEmpty($nonce);

        // now submit a valid TOTP via the X-Preauth header
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode()); // SEE_OTHER
        self::assertTrue($response->headers->has('Location'));
        // a session cookie should be set
        $cookies = $response->headers->getCookies();
        $hasPreauthCookie = false;
        foreach ($cookies as $cookie) {
            if (str_contains($cookie->getName(), 'Preauth')) {
                $hasPreauthCookie = true;
            }
        }
        self::assertTrue($hasPreauthCookie, 'Expected a preauth cookie to be set after login');
    }

    public function testSuccessfulLoginReturnsJsonWhenJsonRequested(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'bob',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = json_decode($response->getContent(), true);
        self::assertSame('Login successful', $body['message']);
    }

    public function testSuccessfulLoginReturnsHtmlWhenJsonFalse(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'carol',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => false,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    public function testAuthenticatedCookieAccessAfterLogin(): void {
        $client = static::createClient();

        // login
        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'dave',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);

        // grab the cookie value from the login response
        $loginResponse = $client->getResponse();
        $cookieValue = null;
        foreach ($loginResponse->headers->getCookies() as $cookie) {
            if (str_contains($cookie->getName(), 'Preauth')) {
                $cookieValue = $cookie->getValue();
            }
        }
        self::assertNotNull($cookieValue);

        // the cookie was set with secure=true, so the CookieJar will only
        // send it over HTTPS; the KernelBrowser automatically updates the
        // CookieJar from the login response, so the next request over HTTPS
        // will include it
        $client->request('GET', 'https://localhost/dashboard');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dave', $response->headers->get('Remote-User'));
    }

    public function testScopeNoneReturnsPlainTextWithoutRedirect(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'eve',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'scope' => 'none',
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('eve', $response->headers->get('Remote-User'));
        // no redirect for scope=none
        self::assertFalse($response->headers->has('Location'));
    }

    /* ── failed login ─────────────────────────────────────────────────── */

    public function testFailedLoginReturnsUnauthorizedJsonWithError(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => '000000', // wrong code
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('nonce', $body);
        // a fresh nonce should be returned for the next attempt
        self::assertNotEmpty($body['nonce']);
    }

    public function testFailedLoginReturnsHtmlWhenJsonFalse(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => 'wrong-code',
                'nonce' => $nonce,
                'json'  => false,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        self::assertSelectorExists('form#preauth-form');
    }

    public function testFailedLoginWithSpentNonceIsRejected(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        // first: successful login consumes the nonce
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        // the successful login set a session cookie; clear it so the next
        // request is not auto-authenticated by AcceptListener before the
        // login attempt is even evaluated
        $client->getCookieJar()->clear();

        // reuse the same nonce — should fail even with a valid token
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFailedLoginWithInvalidNonceIsRejected(): void {
        $client = static::createClient();

        // skip fetching a real nonce; use one that was never stored
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => $this->validTotpCode(),
                'nonce' => 'never-issued-nonce',
                'json'  => true,
            ]),
        ]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /* ── invalid payload ──────────────────────────────────────────────── */

    public function testInvalidHeaderPayloadReturnsUnauthorized(): void {
        $client = static::createClient();

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => '!!!not-valid-base64!!!',
        ]);

        // decode fails -> null payload -> failure path -> 401
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testPayloadWithMissingFieldsReturnsUnauthorized(): void {
        $client = static::createClient();

        // payload missing token
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'alice', 'nonce' => 'some-nonce',
            ]),
        ]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /* ── invalid cookie ───────────────────────────────────────────────── */

    public function testInvalidCookieIsClearedAndLoginPageShown(): void {
        $client = static::createClient();

        // the cookie must be set via the CookieJar so that the HttpFoundation
        // Request actually populates its cookies bag (HTTP_COOKIE alone is
        // not parsed by Request::create)
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(
                self::COOKIE_NAME, 'invalid-ulid-value',
                null, '/', 'localhost', true, true, false, 'Strict',
            )
        );

        $client->request('GET', 'https://localhost/');

        $response = $client->getResponse();
        // not authenticated -> login page with 401
        self::assertSame(401, $response->getStatusCode());
        // the stale cookie should be cleared
        $cleared = false;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::COOKIE_NAME && $cookie->isCleared()) {
                $cleared = true;
            }
        }
        self::assertTrue($cleared, 'Expected the invalid cookie to be cleared');
    }

    /* ── backup code authentication ───────────────────────────────────── */

    public function testBackupCodeAuthenticationWorks(): void {
        $client = static::createClient();
        $container = $client->getContainer();

        // generate a backup code via the BackupCodeManager
        $manager = $container->get(\App\Service\BackupCodeInterface::class);
        $codes = $manager->generate(1);
        self::assertCount(1, $codes);

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'frank',
                'token' => $codes[0],
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);

        self::assertSame(303, $client->getResponse()->getStatusCode());
    }

    public function testConsumedBackupCodeCannotBeReused(): void {
        $client = static::createClient();
        $container = $client->getContainer();

        $manager = $container->get(\App\Service\BackupCodeInterface::class);
        $codes = $manager->generate(1);
        $code = $codes[0];

        // first use
        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'frank', 'token' => $code, 'nonce' => $nonce, 'json' => true,
            ]),
        ]);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        // the successful login set a session cookie; clear it so the next
        // request reaches the login page instead of being auto-authenticated
        $client->getCookieJar()->clear();

        // second use with a fresh nonce
        $crawler = $client->request('GET', '/');
        $nonce2 = $crawler->filter('input[name="nonce"]')->attr('value');
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'frank', 'token' => $code, 'nonce' => $nonce2, 'json' => true,
            ]),
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /* ── return URL handling ──────────────────────────────────────────── */

    public function testSuccessfulLoginWithValidReturnUrl(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/?return=https://example.com/app');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/?return=https://example.com/app', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'alice', 'token' => $this->validTotpCode(),
                'nonce' => $nonce, 'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://example.com/app', $response->headers->get('Location'));
    }

    public function testSuccessfulLoginWithInvalidReturnFallsBackToPath(): void {
        $client = static::createClient();

        $crawler = $client->request('GET', '/?return=not-a-url');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/?return=not-a-url', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'alice', 'token' => $this->validTotpCode(),
                'nonce' => $nonce, 'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        $location = $response->headers->get('Location');
        // should fall back to the request path (with query string),
        // not redirect to the invalid return URL as an absolute URL
        self::assertStringStartsWith('/', $location);
        // the invalid return URL is not used as the redirect target
        self::assertStringNotContainsString('//not-a-url', $location);
    }
}
