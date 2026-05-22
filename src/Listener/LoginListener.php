<?php
declare(strict_types=1);

namespace App\Listener;

use App\Data\Payload;
use App\Enum\Scope;
use App\MonitorCacheKeys;
use App\Service\BackupCodeManager;
use App\Service\DomainManager;
use App\Trait\CookieNameTrait;
use App\Trait\GetTotpTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class LoginListener {
    use CookieNameTrait;
    use HasLoggerTrait;
    use MakeNonceTrait;
    use StringTrait;
    use GetTotpTrait;

    private CacheItemPoolInterface $sessionCache;
    private RateLimiterFactoryInterface $rateLimiter;
    private BackupCodeManager $backupCodeManager;

    /** @throws InvalidArgumentException */
    public function __construct(
        private                    Environment                 $twig,
                                   CacheItemPoolInterface      $sessionCache,
        #[Target('login_limiter')] RateLimiterFactoryInterface $rateLimiter,
                                   BackupCodeManager           $backupCodeManager,
        private                    DomainManager               $domainManager,
    ) {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
        $this->rateLimiter  = $rateLimiter;
        $this->backupCodeManager = $backupCodeManager;
    }

    /** @throws InvalidArgumentException|LoaderError|RuntimeError|SyntaxError */
    #[AsEventListener(priority: 66)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($event->getRequest()->headers->has($this->headerName())) {
            $data = $event->getRequest()->headers->get($this->headerName());
            $payload = Payload::decode($data);
            $response = null;
            if ($payload) {
                $response = $this->checkToken($payload, $event->getRequest());
            }

            /* token or password authentication was successful */
            if ($response) {
                $event->setResponse($response);
                return;
            }

            $limitReached = $this->logFailure($event->getRequest());

            $this->logger->debug("logging failure for: {$event->getRequest()->getClientIp()}");
            $event->setResponse($this->makeFailedResponse($limitReached, $payload->json ?? true));
        }
    }

    /** @throws InvalidArgumentException */
    private function checkToken(Payload $payload, Request $request): ?Response {
        /* When scope is Ip but ip-access is disabled, scope will be considered Cookie. */
        if ($payload->scope === Scope::Ip && ! $this->config->ipTtl()) {
            /* requested to grant ip access, but that is not enabled */
            $payload->scope = Scope::Cookie;
        }

        if ($this->getTotp()->verify($payload->token, null, 10) ||
            $this->backupCodeManager->verifyAndConsume($payload->token)
        ) {
            /* token is correct (TOTP or Backup) */

            /* if server nonce is found and is valid */
            $nonceItem = $this->nonceCache->getItem($this->makeCacheKey($payload->nonce));
            if ($nonceItem->isHit() && $nonceItem->get()) {
                /* mark nonce as spent */
                $nonceItem->set(false); /* invalid */
                $nonceItem->expiresAfter(LoginListener::NONCE_TTL); /* keep briefly */
                $this->nonceCache->save($nonceItem);

                /* token authentication successful, grant access and set response */
                $cleanId = $this->makeCacheKey($payload->id);

                /* if they just want this one page, return ok, to grant them access */
                $response = new Response("hi $cleanId",
                    headers: ['Content-Type' => 'text/plain']
                );

                if ($payload->scope !== Scope::None) {
                    /* grant access based on the requested scope */
                    if ($payload->scope === Scope::Cookie) {
                        $response->headers->setCookie($this->setCookie($cleanId));
                    } else if ($payload->scope === Scope::Ip) {
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

                    $returnKey = $this->config->query('return');
                    $location = $request->query->has($returnKey) &&
                        $this->domainManager->validReturn($request->query->get($returnKey)) ?
                        "{$request->query->get($returnKey)}" :
                        "{$request->getPathInfo()}{$request->getQueryString()}";

                    $response->setContent($content)
                        ->setStatusCode(Response::HTTP_TEMPORARY_REDIRECT)
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
    private function setCookie(string $id): Cookie {
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

        if ($this->domainManager->authBase()) {
            /* when using subdomain-auth we have to use a different cookie name, as the
             * "__Host-Http-" prefix we normally use does not allow domain to be set */
            return Cookie::create(
                name: $this->authCookieName(),
                value: $ulid->toString(),
                expire: time() + $this->config->cookieTtl(),
                path: '/',
                domain: $this->domainManager->authBase(),
                secure: true,
                httpOnly: true,
                sameSite: Cookie::SAMESITE_STRICT,
            );
        } else {
            return Cookie::create(
                name: $this->cookieName(),
                value: $ulid->toString(),
                expire: time() + $this->config->cookieTtl(),
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: Cookie::SAMESITE_STRICT,
            );
        }
    }

    /** @throws InvalidArgumentException */
    private function setIp(string $id, string $ip): void {
        /* successful auth with token, requested scope of ip (and ip access enabled) */
        $ipKey = $this->makeCacheKey("ip_$ip");

        $sessionIp = $this->sessionCache->getItem($ipKey);
        $sessionIp->set($id);
        $sessionIp->expiresAfter($this->config->ipTtl());
        $this->sessionCache->save($sessionIp);
    }

    private function logFailure(Request $request): bool {
        $limiter = $this->rateLimiter->create($request->getClientIp());
        return ($limiter->consume(1)->getRemainingTokens() < 1);
    }

    /** @throws InvalidArgumentException|RuntimeError|SyntaxError|LoaderError */
    private function makeFailedResponse(bool $limited, bool $json): Response {
        if ($limited) {
            $status = $this->config->teapot() ? Response::HTTP_I_AM_A_TEAPOT
                : Response::HTTP_TOO_MANY_REQUESTS;
            $message = $this->config->teapot() ? $this->config->teapotTitle()
                : $this->config->tooManyTitle();
        } else {
            $status  = Response::HTTP_UNAUTHORIZED;
            $message = $this->config->errorMessage();
        }
        $answer = [
            'message' => $message,
            'nonce'   => $this->makeNonce(),
        ];

        if ($json) {
            $contentType = 'application/json';
            $content     = json_encode($answer);
        } else {
            $contentType = 'text/html';
            $content     = $this->twig->render('login.html.twig', $answer);
        }

        return new Response($content, $status, ["Content-Type" => $contentType]);
    }
}
