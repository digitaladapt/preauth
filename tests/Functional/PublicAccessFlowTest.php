<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end functional tests for the public rate-limited access feature.
 *
 * The test environment (phpunit.dist.xml) configures:
 *   PUBLIC_PATHS=/public/**
 *   PUBLIC_BURST_COUNT=3, PUBLIC_BURST_TIME=60
 *   PUBLIC_UPPER_COUNT=10000 (effectively unlimited for test purposes)
 *
 * @covers \App\Listener\PublicAccessListener
 * @covers \App\Service\PublicPathMatcher
 */
final class PublicAccessFlowTest extends WebTestCase
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

    /* ── public path accessible without auth ───────────────────────────── */

    public function testPublicPathAccessibleWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/some-repo');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        // No Remote-User header for public access
        self::assertFalse($response->headers->has('Remote-User'));
    }

    public function testPublicPathWithQuerystringAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/repo?tab=issues&page=2');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testDeepPublicPathAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/org/repo/issues/42');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /* ── non-public path requires auth ─────────────────────────────────── */

    public function testNonPublicPathShowsLoginPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/private/settings');

        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSelectorExists('form#preauth-form');
    }

    public function testRootPathShowsLoginPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testExactPublicPathWithoutSlashNotMatched(): void
    {
        // /public/** does NOT match /public (no trailing content)
        $client = static::createClient();
        $client->request('GET', '/public');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /* ── rate limiting ─────────────────────────────────────────────────── */

    public function testRateLimitEnforcedAfterBurstExceeded(): void
    {
        $client = static::createClient();

        // PUBLIC_BURST_COUNT=3 — first 3 requests succeed
        for ($i = 0; $i < 3; $i++) {
            $client->request('GET', '/public/repo');
            self::assertSame(
                200,
                $client->getResponse()->getStatusCode(),
                "Request $i should have been allowed"
            );
        }

        // 4th request should be rate limited
        $client->request('GET', '/public/repo');
        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
        $retryAfter = (int) $response->headers->get('Retry-After');
        self::assertGreaterThan(0, $retryAfter);
    }

    /* ── authenticated user bypasses public rate limiter ───────────────── */

    public function testAuthenticatedUserBypassesPublicRateLimit(): void
    {
        $client = static::createClient();

        // First, exhaust the public rate limiter
        for ($i = 0; $i < 4; $i++) {
            $client->request('GET', '/public/repo');
        }
        // Confirm rate limit is in effect
        $client->request('GET', '/public/repo');
        self::assertSame(429, $client->getResponse()->getStatusCode());

        // Now log in — the cookie should let us bypass public rate limiting
        $client->getCookieJar()->clear();

        $crawler = $client->request('GET', '/private');
        $nonce = $crawler->filter('input[name="nonce"]')->attr('value');

        $client->request('GET', '/private', [], [], [
            'HTTP_X-Preauth' => $this->encodePayload([
                'id'    => 'alice',
                'token' => $this->validTotpCode(),
                'nonce' => $nonce,
                'json'  => true,
            ]),
        ]);
        self::assertSame(303, $client->getResponse()->getStatusCode());

        // Now visit a public path while authenticated — should get 200
        // (AcceptListener runs before PublicAccessListener, so the public
        // rate limiter is never consulted)
        $client->request('GET', '/public/repo');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        // Authenticated users get Remote-User header
        self::assertSame('alice', $response->headers->get('Remote-User'));
    }

    /* ── 200 response has correct content type ─────────────────────────── */

    public function testPublicAccessResponseIsPlainText(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/repo');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    /* ── 429 response renders error template ───────────────────────────── */

    public function testRateLimitedResponseRendersErrorTemplate(): void
    {
        $client = static::createClient();

        // Exhaust rate limit
        for ($i = 0; $i < 4; $i++) {
            $client->request('GET', '/public/repo');
        }

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        // The error template renders either teapot or too-many-requests content
        // Default test env has TEAPOT=true
        self::assertNotEmpty($content);
    }

    /* ── security headers still applied to public responses ────────────── */

    public function testSecurityHeadersOnPublicAccess(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public/repo');

        $response = $client->getResponse();
        // SecurityHeadersListener runs on all main-request responses
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }
}
