<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ConfigBag;
use App\Data\Payload;
use App\Enum\Scope;
use App\Service\BackupCodeInterface;
use App\Service\DomainManager;
use App\Service\LoginManager;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LoginManagerTest extends TestCase {
    private ?TOTP $totp = null;
    private ?ClockInterface $clock = null;

    private function getClock(): ClockInterface {
        if ($this->clock === null) {
            $now = time();
            $this->clock = $this->createMock(ClockInterface::class);
            $this->clock->method('now')->willReturn(new \DateTimeImmutable('@' . $now));
        }
        return $this->clock;
    }

    private function getTotp(): TOTP {
        if ($this->totp === null) {
            $this->totp = TOTP::generate($this->getClock());
            $this->totp->setLabel('Test');
        }
        return $this->totp;
    }

    private function createConfigBag(int $ipTtl = 1800): ConfigBag {
        $clock = $this->getClock();
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $utilities = new \App\Utilities($clock, $cache);

        return new ConfigBag(
            $utilities,
            $clock,
            3600,
            $this->getTotp()->getProvisioningUri(),
            $ipTtl,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    private function getCurrentTotpToken(): string {
        return $this->getTotp()->now();
    }

    private function createManager(
        ArrayAdapter $cache,
        ?BackupCodeInterface $backupCodeManager = null,
        ?DomainManager $domainManager = null,
        ?ArrayAdapter $nonceCache = null
    ): LoginManager {
        $bcm = $backupCodeManager ?? $this->createMock(BackupCodeInterface::class);
        $dm = $domainManager ?? new DomainManager(false, '');
        $manager = new LoginManager($cache, $bcm, $dm);
        $manager->setConfig($this->createConfigBag());
        $manager->setLogger($this->createMock(LoggerInterface::class));

        $nc = $nonceCache ?? new ArrayAdapter();
        if (!$nonceCache) {
            $nonceItem = $nc->getItem('nonce_abc');
            $nonceItem->set(true);
            $nc->save($nonceItem);
        }
        $manager->setNonceCache($nc);

        return $manager;
    }

    public function testCheckTokenWithValidTotpAndCookieScope(): void {
        $cache = new ArrayAdapter();
        $manager = $this->createManager($cache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('Login successful', $response->getContent());
        self::assertTrue($response->headers->hasCookie('__Host-Http-Preauth'));
    }

    public function testCheckTokenWithValidTotpAndNoneScope(): void {
        $cache = new ArrayAdapter();
        $manager = $this->createManager($cache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'abc';
        $payload->json = false;
        $payload->scope = Scope::None;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hi user1', $response->getContent());
        self::assertFalse($response->headers->hasCookie('__Host-Http-Preauth'));
    }

    public function testCheckTokenWithInvalidToken(): void {
        $cache = new ArrayAdapter();
        $manager = $this->createManager($cache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = 'invalid';
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNull($response);
    }

    public function testCheckTokenWithValidBackupCode(): void {
        $cache = new ArrayAdapter();

        $backupManager = $this->createMock(BackupCodeInterface::class);
        $backupManager->method('verifyAndConsume')->willReturn(true);

        $manager = $this->createManager($cache, $backupManager);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = 'backup123';
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
    }

    public function testCheckTokenWithIpScopeWhenDisabled(): void {
        $cache = new ArrayAdapter();
        $manager = $this->createManager($cache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Ip;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        // When ipTtl is 0, scope falls back to Cookie
        self::assertTrue($response->headers->hasCookie('__Host-Http-Preauth'));
    }

    public function testCheckTokenWithInvalidNonce(): void {
        $cache = new ArrayAdapter();

        // Create nonce cache with invalid nonce
        $nonceCache = new ArrayAdapter();
        $nonceItem = $nonceCache->getItem('nonce_bad');
        $nonceItem->set(false);
        $nonceCache->save($nonceItem);

        $manager = $this->createManager($cache, nonceCache: $nonceCache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'bad';
        $payload->json = true;
        $payload->scope = Scope::None;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNull($response);
    }

    public function testCheckTokenWithMissingNonce(): void {
        $cache = new ArrayAdapter();

        // Create empty nonce cache (missing nonce)
        $nonceCache = new ArrayAdapter();

        $manager = $this->createManager($cache, nonceCache: $nonceCache);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'missing';
        $payload->json = true;
        $payload->scope = Scope::None;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNull($response);
    }

    public function testCheckTokenWithUlidCollisionThrows(): void {
        $cache = new ArrayAdapter();
        $manager = $this->createManager($cache);

        // Pre-populate cache with a cookie to simulate collision
        $cookieItem = $cache->getItem('cookie_test');
        $cookieItem->set('existing');
        $cache->save($cookieItem);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->getCurrentTotpToken();
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('https://example.com/');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $manager->checkToken($payload, $request);
    }
}
