<?php

declare(strict_types=1);

namespace App\Service;

use App\ConfigBag;
use App\Enum\Scope;
use App\MonitorCacheKeys;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Grants access once an identity has been authenticated, by whatever method.
 *
 * Extracted from `LoginManager` so the passkey ceremony and the TOTP form
 * produce **byte-identical** outcomes. Two implementations would inevitably
 * drift — most likely in cookie attributes, where a difference is invisible
 * until it breaks in a browser.
 *
 * This class deliberately knows nothing about *how* authentication happened; it
 * only records the result.
 *
 * @see SessionIssuerInterface
 */
final readonly class SessionIssuer implements SessionIssuerInterface
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use StringTrait;

    private MonitorCacheKeys $sessionCache;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        #[Target('sessionCache')] CacheItemPoolInterface $sessionCache,
        private DomainInterface $domainManager,
        private ConfigBag $config,
    ) {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function issue(string $identity, Scope $scope, Request $request, bool $json): Response
    {
        /* the same normalisation LoginManager has always applied, so cache keys
         * and Remote-User values stay identical between the two login paths */
        $cleanId = $this->makeCacheKey($identity);

        $response = $this->authSuccessResponse($cleanId, $this->config);

        /* when the caller only wanted this one page, there is nothing to store */
        if (Scope::None === $scope) {
            $this->logger->debug("successful login for: $cleanId");

            return $response;
        }

        if (Scope::Cookie === $scope) {
            $response->headers->setCookie($this->setCookie($cleanId, $request->getHost()));
        } elseif (Scope::Ip === $scope) {
            $this->setIp($cleanId, (string) $request->getClientIp());
        }

        if ($json) {
            $contentType = 'application/json';
            $content = (string) json_encode([
                'message' => 'Login successful',
                'nonce' => null,
            ]);
        } else {
            $contentType = 'text/html';
            $content = "hi $cleanId, please reload";
        }

        $location = $request->query->has('return')
        && $this->domainManager->validReturn((string) $request->query->get('return')) ?
            "{$request->query->get('return')}" :
            "{$request->getPathInfo()}{$request->getQueryString()}";

        /* force redirect to use GET method (important when using central auth) */
        $response->setContent($content)
            ->setStatusCode(Response::HTTP_SEE_OTHER)
            ->headers->set('Location', $location);
        $response->headers->set('Content-Type', $contentType);

        $this->logger->debug("successful login for: $cleanId");

        return $response;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function setCookie(string $id, string $host): Cookie
    {
        /* successful auth with token, store session and set the cookie */
        $ulid = new Ulid();
        $sessionCookie = $this->sessionCache->getItem(
            $this->makeCacheKey("cookie_$ulid"),
        );
        if ($sessionCookie->isHit()) {
            /* it is supposed to be impossible to have collisions */
            $this->logger->error('aborting: ULID collision');
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

    /**
     * @throws InvalidArgumentException
     */
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
