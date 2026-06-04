<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ConfigBag;
use App\Data\Payload;
use App\Enum\Scope;
use App\Service\BackupCodeManager;
use App\Service\DomainManager;
use App\Service\LoginManager;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LoginManagerTest extends TestCase {
    private function createMockItem(string $key, mixed $value = null, bool $isHit = true): CacheItemInterface {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        return $item;
    }

    private function createConfigBag(int $ipTtl = 1800): ConfigBag {
        $clock = $this->createMock(ClockInterface::class);
        $utilities = $this->createMock(\App\Utilities::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        return new ConfigBag(
            $utilities,
            $clock,
            3600,
            $totp->getProvisioningUri(),
            $ipTtl,
            false,
            'Error',
            'Teapot',
            'Too Many'
        );
    }

    private function createManager(
        CacheItemPoolInterface $cache,
        ?BackupCodeManager $backupCodeManager = null,
        ?DomainManager $domainManager = null
    ): LoginManager {
        $bcm = $backupCodeManager ?? $this->createMock(BackupCodeManager::class);
        $dm = $domainManager ?? new DomainManager(false, '');
        $manager = new LoginManager($cache, $bcm, $dm);
        $manager->setConfig($this->createConfigBag());
        $manager->setLogger($this->createMock(LoggerInterface::class));

        $nonceCache = $this->createMock(CacheItemPoolInterface::class);
        $nonceItem = $this->createMockItem('nonce_abc', true, true);
        $nonceCache->method('getItem')->willReturn($nonceItem);
        $nonceCache->method('save')->willReturn(true);
        $manager->setNonceCache($nonceCache);

        return $manager;
    }

    public function testCheckTokenWithValidTotpAndCookieScope(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $cache->method('getItem')
            ->willReturnCallback(function ($key) {
                if (str_starts_with($key, 'cookie_')) {
                    return $this->createMockItem($key, null, false);
                }
                return $this->createMockItem($key, null, false);
            });
        $cache->method('saveDeferred')->willReturn(true);
        $cache->method('commit')->willReturn(true);
        $cache->method('save')->willReturn(true);

        $manager = $this->createManager($cache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
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
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $manager = $this->createManager($cache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
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
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

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
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $cache->method('getItem')
            ->willReturnCallback(function ($key) {
                if (str_starts_with($key, 'cookie_')) {
                    return $this->createMockItem($key, null, false);
                }
                return $this->createMockItem($key, null, false);
            });
        $cache->method('saveDeferred')->willReturn(true);
        $cache->method('commit')->willReturn(true);
        $cache->method('save')->willReturn(true);

        $backupManager = $this->createMock(BackupCodeManager::class);
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
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $cache->method('getItem')
            ->willReturnCallback(function ($key) {
                if (str_starts_with($key, 'cookie_')) {
                    return $this->createMockItem($key, null, false);
                }
                return $this->createMockItem($key, null, false);
            });
        $cache->method('saveDeferred')->willReturn(true);
        $cache->method('commit')->willReturn(true);
        $cache->method('save')->willReturn(true);

        $manager = $this->createManager($cache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
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
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $manager = $this->createManager($cache);

        $nonceCache = $this->createMock(CacheItemPoolInterface::class);
        $nonceItem = $this->createMockItem('nonce_bad', false, true);
        $nonceCache->method('getItem')->willReturn($nonceItem);
        $manager->setNonceCache($nonceCache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
        $payload->nonce = 'bad';
        $payload->json = true;
        $payload->scope = Scope::None;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNull($response);
    }

    public function testCheckTokenWithMissingNonce(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);

        $manager = $this->createManager($cache);

        $nonceCache = $this->createMock(CacheItemPoolInterface::class);
        $nonceItem = $this->createMockItem('nonce_missing', null, false);
        $nonceCache->method('getItem')->willReturn($nonceItem);
        $manager->setNonceCache($nonceCache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
        $payload->nonce = 'missing';
        $payload->json = true;
        $payload->scope = Scope::None;

        $request = Request::create('https://example.com/');
        $response = $manager->checkToken($payload, $request);

        self::assertNull($response);
    }

    public function testCheckTokenWithUlidCollisionThrows(): void {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $keyListItem = $this->createMockItem('__key_list', [], true);
        $changeListItem = $this->createMockItem('__chg_list', [], true);

        $cache->method('getItems')
            ->with(['__key_list', '__chg_list'])
            ->willReturn([$keyListItem, $changeListItem]);
        $cache->method('getItem')
            ->willReturnCallback(function ($key) {
                if (str_starts_with($key, 'cookie_')) {
                    // Simulate collision
                    return $this->createMockItem($key, 'existing', true);
                }
                return $this->createMockItem($key, null, false);
            });

        $manager = $this->createManager($cache);

        $clock = $this->createMock(ClockInterface::class);
        $totp = TOTP::generate($clock);
        $totp->setLabel('Test');

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $totp->now();
        $payload->nonce = 'abc';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('https://example.com/');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $manager->checkToken($payload, $request);
    }
}
