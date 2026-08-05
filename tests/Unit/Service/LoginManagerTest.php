<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Data\Payload;
use App\Enum\Scope;
use App\Service\BackupCodeInterface;
use App\Service\DomainManager;
use App\Trait\StringTrait;
use App\Service\LoginManager;
use App\Tests\Support\TotpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LoginManagerTest extends TestCase {
    use TotpTestHelper;
    use StringTrait;

    private ArrayAdapter $pool;
    private BackupCodeInterface $backupCodeManager;
    private DomainManager $domainManager;

    private function makeLoginManager(
        ?int $ipTtl = 0,
        bool $subdomainRedirect = false,
        string $authSubdomain = '',
    ): LoginManager {
        $this->pool = new ArrayAdapter();
        $this->backupCodeManager = $this->createStub(BackupCodeInterface::class);
        $this->domainManager = new DomainManager($subdomainRedirect, $authSubdomain);

        $manager = new LoginManager($this->pool, $this->backupCodeManager, $this->domainManager);
        $manager->setConfig($this->makeConfig(ipTtl: $ipTtl));
        $manager->setLogger(new NullLogger());
        $manager->setNonceCache(new ArrayAdapter());
        return $manager;
    }

    /** Build a Payload with a valid server-side nonce already stored. */
    private function makePayloadWithNonce(
        LoginManager $manager,
        string $id = 'testuser',
        Scope $scope = Scope::Cookie,
        ?string $token = null,
    ): Payload {
        $token ??= $this->validTotpCode();
        $nonce = $this->insertNonce($manager, 'test-nonce-123');

        $payload = new Payload();
        $payload->id = $id;
        $payload->token = $token;
        $payload->nonce = $nonce;
        $payload->json = true;
        $payload->scope = $scope;
        return $payload;
    }

    /** Inject a nonce directly into the manager's nonce cache. */
    private function insertNonce(LoginManager $manager, string $nonce): string {
        $reflection = new \ReflectionProperty(LoginManager::class, 'nonceCache');
        $nonceCache = $reflection->getValue($manager);

        $key = $this->makeCacheKey($nonce);
        $item = $nonceCache->getItem($key);
        $item->set(true);
        $nonceCache->save($item);

        return $nonce;
    }

    public function testCheckTokenReturnsNullForInvalidTotp(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, token: 'wrong-code');

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET');

        self::assertNull($manager->checkToken($payload, $request));
    }

    public function testCheckTokenReturnsNullForSpentNonce(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        // spend the nonce first (use the same cache key the manager does)
        $reflection = new \ReflectionProperty(LoginManager::class, 'nonceCache');
        $nonceCache = $reflection->getValue($manager);
        $nonceItem = $nonceCache->getItem($this->makeCacheKey('test-nonce-123'));
        $nonceItem->set(false);
        $nonceCache->save($nonceItem);

        $request = Request::create('/', 'GET');

        self::assertNull($manager->checkToken($payload, $request));
    }

    public function testCheckTokenReturnsNullForMissingNonce(): void {
        $manager = $this->makeLoginManager();

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $payload = new Payload();
        $payload->id = 'user1';
        $payload->token = $this->validTotpCode();
        $payload->nonce = 'never-stored';
        $payload->json = true;
        $payload->scope = Scope::Cookie;

        $request = Request::create('/', 'GET');

        self::assertNull($manager->checkToken($payload, $request));
    }

    public function testSuccessfulTotpLoginWithCookieScopeReturnsRedirect(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Cookie);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/dashboard', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame(303, $response->getStatusCode()); // HTTP_SEE_OTHER
        self::assertTrue($response->headers->has('Location'));
        self::assertTrue($response->headers->has('Set-Cookie'));
    }

    public function testSuccessfulLoginWithNoneScopeReturnsPlainResponse(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::None);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->has('Remote-User'));
        // no redirect for Scope::None
        self::assertFalse($response->headers->has('Location'));
    }

    public function testSuccessfulLoginSetsRemoteUserHeader(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, id: 'alice', scope: Scope::None);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame('alice', $response->headers->get('Remote-User'));
    }

    public function testSuccessfulLoginJsonResponse(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Cookie, token: null);
        $payload->json = true;

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/protected', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $body = json_decode($response->getContent(), true);
        self::assertSame('Login successful', $body['message']);
    }

    public function testSuccessfulLoginHtmlResponse(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Cookie);
        $payload->json = false;

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/protected', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testSuccessfulLoginWithReturnUrl(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Cookie);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/login?return=https://example.com/app', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame('https://example.com/app', $response->headers->get('Location'));
    }

    public function testSuccessfulLoginWithInvalidReturnFallsBackToPath(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Cookie);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/login?return=not-a-url', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        $location = $response->headers->get('Location');
        self::assertStringStartsWith('/login', $location);
    }

    public function testIpScopeDowngradesToCookieWhenIpAccessDisabled(): void {
        $manager = $this->makeLoginManager(ipTtl: 0);
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Ip);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET');

        $response = $manager->checkToken($payload, $request);

        // Should have a Set-Cookie (downgraded to cookie scope)
        self::assertNotNull($response);
        self::assertTrue($response->headers->has('Set-Cookie'));
    }

    public function testIpScopeWhenEnabledSetsIpSession(): void {
        $manager = $this->makeLoginManager(ipTtl: 1800);
        $payload = $this->makePayloadWithNonce($manager, scope: Scope::Ip);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        // IP session should be stored; no Set-Cookie for IP scope
        self::assertFalse($response->headers->has('Set-Cookie'));

        // verify the IP session exists in the cache
        $reflection = new \ReflectionProperty(LoginManager::class, 'sessionCache');
        $sessionCache = $reflection->getValue($manager);
        self::assertTrue($sessionCache->hasItem('ip_1.2.3.4'));
    }

    public function testBackupCodeAuthentication(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager, token: 'backup-code-123');

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(true);

        $request = Request::create('/', 'GET');

        $response = $manager->checkToken($payload, $request);

        self::assertNotNull($response);
        self::assertSame(303, $response->getStatusCode());
    }

    public function testNonceIsConsumedAfterSuccessfulLogin(): void {
        $manager = $this->makeLoginManager();
        $payload = $this->makePayloadWithNonce($manager);

        $this->backupCodeManager->method('verifyAndConsume')->willReturn(false);

        $request = Request::create('/', 'GET');

        $manager->checkToken($payload, $request);

        // nonce should now be marked invalid (false); look it up via the same
        // cache key the manager uses (makeCacheKey rewrites '-' to '_')
        $reflection = new \ReflectionProperty(LoginManager::class, 'nonceCache');
        $nonceCache = $reflection->getValue($manager);
        $nonceItem = $nonceCache->getItem($this->makeCacheKey('test-nonce-123'));
        self::assertFalse($nonceItem->get());
    }
}
