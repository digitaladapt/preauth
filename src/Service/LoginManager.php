<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\Payload;
use App\Enum\Scope;
use App\MonitorCacheKeys;
use App\Trait\CookieNameTrait;
use App\Trait\GetTotpTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;

final readonly class LoginManager implements LoginInterface
{
    use CookieNameTrait;
    use GetTotpTrait;
    use MakeNonceTrait;
    use StringTrait;

    private CacheItemPoolInterface $sessionCache;

    /** @throws InvalidArgumentException */
    public function __construct(
        CacheItemPoolInterface $sessionCache,
        private BackupCodeInterface    $backupCodeManager,
        private DomainInterface        $domainManager,
    ) {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
    }

    /** @throws InvalidArgumentException */
    public function checkToken(Payload $payload, Request $request): ?Response
    {
        /* when scope is IP but ip-access is disabled, scope is to be considered cookie */
        if ($payload->scope === Scope::Ip && ! $this->config->ipTtl()) {
            /* requested to grant ip access, but that is not enabled */
            $payload->scope = Scope::Cookie;
        }

        if ($this->getTotp()->verify($payload->token, null, 1) ||
            $this->backupCodeManager->verifyAndConsume($payload->token)
        ) {
            /* token is correct (TOTP or Backup) */

            /* if server nonce is found and is valid */
            $nonceItem = $this->nonceCache->getItem($this->makeCacheKey($payload->nonce));
            if ($nonceItem->isHit() && $nonceItem->get()) {
                /* mark nonce as spent */
                $nonceItem->set(false); /* invalid */
                $nonceItem->expiresAfter(LoginManager::NONCE_TTL); /* keep briefly */
                $this->nonceCache->save($nonceItem);

                /* token authentication successful, grant access and set response */
                $cleanId = $this->makeCacheKey($payload->id);

                /* if they just want this one page, return ok, to grant them access */
                $response = new Response("hi $cleanId", headers: [
                    'Content-Type' => 'text/plain',
                    'Remote-User'  => $cleanId,
                ]);

                if ($payload->scope !== Scope::None) {
                    /* grant access based on the requested scope */
                    if ($payload->scope === Scope::Cookie) {
                        $response->headers->setCookie($this->setCookie($cleanId, $request->getHost()));
                    } elseif ($payload->scope === Scope::Ip) {
                        $this->setIp($cleanId, $request->getClientIp());
                    }

                    if ($payload->json) {
                        $contentType = 'application/json';
                        $content     = json_encode([
                            'message' => 'Login successful',
                            'nonce'   => null,
                        ]);
                    } else {
                        $contentType = 'text/html';
                        $content     = "hi $cleanId, please reload";
                    }

                    $location = $request->query->has('return') &&
                    $this->domainManager->validReturn($request->query->get('return')) ?
                        "{$request->query->get('return')}" :
                        "{$request->getPathInfo()}{$request->getQueryString()}";

                    /* force redirect to use GET method (important when using central auth) */
                    $response->setContent($content)
                        ->setStatusCode(Response::HTTP_SEE_OTHER)
                        ->headers->set('Location', $location);
                    $response->headers->set('Content-Type', $contentType);
                }

                $this->logger->debug("successful login for: $cleanId");
                return $response;
            }
        }
        return null;
    }

    /** @throws InvalidArgumentException */
    private function setCookie(string $id, string $host): Cookie
    {
        /* successful auth with token, store session and set the cookie */
        $ulid = new Ulid();
        $sessionCookie = $this->sessionCache->getItem(
            $this->makeCacheKey("cookie_$ulid")
        );
        if ($sessionCookie->isHit()) {
            /* it is supposed to be impossible to have collisions */
            $this->logger->error("aborting: ULID collision");
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
        }
        $sessionCookie->set($id);
        $sessionCookie->expiresAfter($this->config->cookieTtl());
        $this->sessionCache->save($sessionCookie);

        return Cookie::create(
            name: $this->sessionCookieName($this->domainManager),
            value: $ulid->toString(),
            expire: time() + $this->config->cookieTtl(),
            path: '/',
            domain: $this->sessionCookieDomain($this->domainManager, $host),
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    /** @throws InvalidArgumentException */
    private function setIp(string $id, string $ip): void
    {
        /* successful auth with token, requested scope of ip (and ip access enabled) */
        $ipKey = $this->makeCacheKey("ip_$ip");

        $sessionIp = $this->sessionCache->getItem($ipKey);
        $sessionIp->set($id);
        $sessionIp->expiresAfter($this->config->ipTtl());
        $this->sessionCache->save($sessionIp);
    }
}
