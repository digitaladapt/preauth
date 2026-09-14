<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end checks that the login flow carries strict anti-caching headers
 * on everything the browser can see, while 2xx grants ("already
 * authenticated" / public access) — which the reverse proxy consumes in its
 * forward_auth check and never forwards to the browser — are left untouched.
 */
final class CacheControlFlowTest extends WebTestCase
{
    private const string TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = parent::createClient($options, $server);
        $client->disableReboot();

        return $client;
    }

    private function validTotpCode(): string
    {
        return TOTP::createFromSecret(self::TOTP_SECRET)->now();
    }

    private function encodePayload(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function assertNotCacheable(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        self::assertTrue($response->headers->hasCacheControlDirective('proxy-revalidate'));
        self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('0', $response->headers->getCacheControlDirective('s-maxage'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('0', $response->headers->get('Expires'));
        self::assertSame('no-store', $response->headers->get('Surrogate-Control'));
        self::assertSame('*', $response->headers->get('Vary'));
    }

    private function assertCacheable(Response $response): void
    {
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertNull($response->headers->get('Pragma'));
        self::assertNull($response->headers->get('Surrogate-Control'));
    }

    /* ── login flow: nothing may be cached ────────────────────────────── */

    public function testLoginPageIsNotCacheable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $this->assertNotCacheable($response);
    }

    public function testLoginPageFetchBypassesHttpCache(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $content = $client->getResponse()->getContent();
        // the inline login script must opt out of the HTTP cache and must
        // not leave the login page in history / the back-forward cache
        self::assertStringContainsString("cache: 'no-store'", $content);
        self::assertStringContainsString('window.location.replace(', $content);
    }

    public function testFailedLoginIsNotCacheable(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'alice', 'token' => '000000', 'nonce' => $nonce, 'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $this->assertNotCacheable($response);
    }

    public function testSuccessfulLoginRedirectIsNotCacheable(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'alice', 'token' => $this->validTotpCode(), 'nonce' => $nonce, 'json' => true,
            ]),
        ]);

        $response = $client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        $this->assertNotCacheable($response);
        // the redirect target must still be present
        self::assertTrue($response->headers->has('Location'));
    }

    public function testLoginPageOnAnotherHostIsNotCacheable(): void
    {
        // the listener applies to every main response, not only the primary
        // host; subdomain redirection itself is covered by InterceptListener
        // unit tests
        $client = static::createClient();
        $client->request('GET', 'https://other.example.com/');

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $this->assertNotCacheable($response);
    }

    public function testRateLimitedResponseIsNotCacheable(): void
    {
        $client = static::createClient();

        // the login limiter is raised for tests, so exercise the public
        // limiter instead (test config: PUBLIC_BURST_COUNT=3)
        for ($i = 0; $i < 4; $i++) {
            $client->request('GET', '/public/repo');
        }

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        $this->assertNotCacheable($response);
    }

    /* ── 2xx grants: must stay untouched ──────────────────────────────── */

    public function testAuthenticatedAccessResponseIsNotModifiedByAntiCachingHeaders(): void
    {
        $client = static::createClient();

        // login and keep the cookie
        $crawler = $client->request('GET', '/');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');
        $client->request('GET', '/', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id' => 'dave', 'token' => $this->validTotpCode(), 'nonce' => $nonce, 'json' => true,
            ]),
        ]);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        // subsequent authenticated requests return a 200 "grant" response
        $client->request('GET', 'https://localhost/dashboard');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dave', $response->headers->get('Remote-User'));
        // 2xx responses are consumed by forward_auth and never reach the
        // browser — they must not carry the login-flow anti-caching headers
        $this->assertCacheable($response);
    }

    public function testPublicAccessResponseIsNotModifiedByAntiCachingHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/repo');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $this->assertCacheable($response);
    }
}
