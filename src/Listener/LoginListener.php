<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Data\Payload;
use App\Enum\Scope;
use App\MonitorCacheKeys;
use App\Trait\CookieNameTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use OTPHP\Factory;
use OTPHP\TOTPInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class LoginListener {
    use CookieNameTrait;
    use MakeNonceTrait;
    use StringTrait;

    private CacheItemPoolInterface $requestPool;
    private CacheItemPoolInterface $sessionPool;

    /** @throws InvalidArgumentException */
    public function __construct(
        private ConfigBag              $config,
        private Environment            $twig,
                CacheItemPoolInterface $noncePool,
                CacheItemPoolInterface $requestPool,
                CacheItemPoolInterface $sessionPool,
                LoggerInterface        $logger,
    ) {
        $this->requestPool = new MonitorCacheKeys($requestPool);
        $this->sessionPool = new MonitorCacheKeys($sessionPool);
        $this->noncePool   = $noncePool;
        $this->logger      = $logger;
    }

    /** @throws InvalidArgumentException|LoaderError|RuntimeError|SyntaxError */
    #[AsEventListener(priority: 66)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($event->getRequest()->headers->has($this->headerName())) {
            $data = $event->getRequest()->headers->get($this->headerName());
            $payload = Payload::decode($data);
            $response = null;
            if ($payload) {
                /* if using token */
                if ($payload->token) {
                    $response = $this->checkToken($payload, $event->getRequest());
                } else if ($this->config->staticSecret()) {
                    $response = $this->checkPassword($payload);
                }
            }

            /* token or password authentication was successful */
            if ($response) {
                $event->setResponse($response);
                return;
            }

            $limitReached = $this->logFailure(
                $payload ? $payload->toString() : $data,
                $event->getRequest()
            );

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

        if ($this->getTotp()->verify($payload->token, null, 10)) {
            /* token is correct */

            /* if server nonce is found and is valid */
            $nonceItem = $this->noncePool->getItem($payload->nonce);
            if ($nonceItem->isHit() && $nonceItem->get()) {
                /* mark nonce as spent */
                $nonceItem->set(false); /* invalid */
                $nonceItem->expiresAfter(static::NONCE_TTL); /* keep breifly */
                $this->noncePool->save($nonceItem);

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

                    $response->setContent($content)
                        ->setStatusCode(Response::HTTP_TEMPORARY_REDIRECT)
                        ->headers->set('Location',
                            "{$request->getPathInfo()}{$request->getQueryString()}"
                        );
                    $response->headers->set('Content-Type', $contentType);
                }

                $this->logger->debug("successful login for: $cleanId");
                return $response;
            }
        }
        return null;
    }

    /** @throws InvalidArgumentException */
    private function checkPassword(Payload $payload): ?Response {
        /* When using password but password is disabled, request will always fail. */

        /* if password is correct */

        if (hash_equals($this->config->staticSecret(), $payload->password)) {
            /* password is correct */

            /* nonce *may* be client provided, but must still be unique */

            /* if server/client nonce is acceptable (valid server or unused client) */
            $nonceItem = $this->noncePool->getItem($payload->nonce);
            if (($nonceItem->isHit() && $nonceItem->get()) || ! $nonceItem->isHit()) {
                /* mark nonce as spent */
                $nonceItem->set(false); /* invalid */
                $nonceItem->expiresAfter(static::NONCE_TTL); /* keep breifly */
                $this->noncePool->save($nonceItem);

                /* password authentication successful, grant access and set response */
                $cleanId = $this->makeCacheKey($payload->id);
                $this->logger->debug("successful login for: $cleanId");
                return new Response("hi $cleanId",
                    headers: ['Content-Type' => 'text/plain']
                );
            }
        }
        return null;
    }

    /** @throws InvalidArgumentException */
    private function setCookie(string $id): Cookie {
        /* successful auth with token, store session and set the cookie */
        $ulid = new Ulid();
        $sessionCookie = $this->sessionPool->getItem(
            $this->makeCacheKey("cookie_$ulid")
        );
        if ($sessionCookie->isHit()) {
            /* it is supposed to be impossible to have collisions */
            $this->logger->error("aborting: ULID collision");
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
        }
        $sessionCookie->set($id);
        $sessionCookie->expiresAfter($this->config->cookieTtl());
        $this->sessionPool->save($sessionCookie);

        return Cookie::create(
            name: $this->cookieName(),
            value: $ulid->toString(),
            expire: time() + $this->config->cookieTtl(),
            secure: true,
            sameSite: Cookie::SAMESITE_STRICT
        );
    }

    /** @throws InvalidArgumentException */
    private function setIp(string $id, string $ip): void {
        /* successful auth with token, requested scope of ip (and ip access enabled) */
        $ipKey = $this->makeCacheKey("ip_$ip");

        $sessionIp = $this->sessionPool->getItem($ipKey);
        $sessionIp->set($id);
        $sessionIp->expiresAfter($this->config->ipTtl());
        $this->sessionPool->save($sessionIp);
    }

    /** @throws InvalidArgumentException */
    private function logFailure(string $data, Request $request): bool {
        // TODO use rate-limiting symfony system (also update RejectListener)
        $timeframe = (int)floor(time() / $this->getTotp()->getPeriod());
        /* hash the data and timeframe, so we do not count duplicates in the same timeframe
         * hitting refresh a few times should not lock you out */
        $ipKey = $this->makeCacheKey("ip_{$request->getClientIp()}");
        $failuresItem = $this->requestPool->getItem($ipKey);
        $failures = $failuresItem->get() ?? [];
        $failures[hash('xxh3', "$timeframe-$data")] = true;
        $limitReached = count($failures) >= $this->config->limit();
        $failuresItem->set($failures);
        $failuresItem->expiresAfter($limitReached
            ? $this->config->limitTtl() : $this->config->limitTimeout()
        );
        $this->requestPool->save($failuresItem);
        return $limitReached;
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

    private function getTotp(): TOTPInterface {
        $otp = Factory::loadFromProvisioningUri(
            $this->config->totpUri(), $this->config->clock()
        );
        if ($otp instanceof TOTPInterface) {
            return $otp;
        }
        throw new HttpException(500, 'Internal Server Exception');
    }
}
