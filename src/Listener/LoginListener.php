<?php
declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\DTO\Payload;
use App\Enum\Scope;
use App\MonitorCacheKeys;
use App\Trait\MakeNonceTrait;
use App\Utilities;
use OTPHP\Factory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

final class LoginListener {
    use MakeNonceTrait;

    public function __construct(
        private CacheItemPoolInterface $requestCache,
        private CacheItemPoolInterface $sessionCache,
                CacheItemPoolInterface $noncePool,
        private ConfigBag              $config,
                LoggerInterface        $logger,
        private Utilities              $utilities,
        private Environment            $twig,
    ) {
        $this->requestCache = new MonitorCacheKeys($requestCache);
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
        $this->noncePool = $noncePool;
        $this->logger    = $logger;
    }

    #[AsEventListener(priority: 88)]
    public function onKernelRequest(RequestEvent $event): void {
        if ($event->getRequest()->headers->has('x-preauth')) {
$this->logger->debug("x-preauth has been provided");
            $data = $event->getRequest()->headers->get('x-preauth');
            $payload = $this->decode($data);
            if ($payload) {
$this->logger->debug("x-preauth has valid payload");
                /* if using token */
                if ($payload->token) {
$this->logger->debug("payload has token: $payload->token");
                    if ($payload->scope === Scope::Ip && ! $this->config->ipTtl()) {
                        /* requested to grant ip access, but that is not enabled */
                        $payload->scope = Scope::Cookie;
                    }
                    if ($payload->scope === Scope::None && $payload->json) {
                        /* when scope is None, json *must* be false */
                        $payload->json = false;
                    }

                    $otp = Factory::loadFromProvisioningUri(
                        $this->config->totpUri(), $this->config->clock()
                    );
$this->logger->debug("token given: $payload->token, token calculated: " . $otp->now());
                    if ($otp->verify($payload->token, leeway: 10)) {
$this->logger->debug("tokens detected to be matching");
                        /* token is correct */

                        /* if server nonce is found and is valid */
                        $nonceItem = $this->noncePool->getItem($payload->nonce);
$this->logger->debug("given nonce: $payload->nonce, is it a hit? " . ($nonceItem->isHit() ? 'hit' : 'miss') . ', is it valid? ' . ($nonceItem->get() ? 'valid' : 'NOPE'));
                        if ($nonceItem->isHit() && $nonceItem->get()) {
                            /* mark nonce as spent */
                            $nonceItem->set(false); /* invalid */
                            $nonceItem->expiresAfter(60); /* keep for 1 minute */
                            $this->noncePool->save($nonceItem);

                            /* token authentication successful, grant access and set response */
                            $cleanId = $this->utilities->makeCacheKey($payload->id);

                            /* if they just want this one page, return ok, to grant them access */
                            $response = new Response("hi $cleanId");

                            if ($payload->scope !== Scope::None) {
                                /* grant access based on the requested scope */
                                if ($payload->scope === Scope::Cookie) {
                                    $response->headers->setCookie($this->setCookie($cleanId));
                                } else if ($payload->scope === Scope::Ip) {
                                    $this->setIp($cleanId, $event->getRequest()->getClientIp());
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
                                     ->headers->set('location',
                                         "{$event->getRequest()->getPathInfo()}{$event->getRequest()->getQueryString()}"
                                     );
                                $response->headers->set('content-type', $contentType);
                            }

                            $this->logger->debug("successful login for: $cleanId");
                            $event->setResponse($response);
                            return;
                        }
                    }
                } else if ($this->config->staticSecret()) {
                    /* when using password, we never set cookie, nor grant ip access, nor return json */
                    $payload->json  = false;
                    $payload->scope = Scope::None;

                    /* if password is correct */
                    if ($payload->password === $this->config->staticSecret()) {
                        /* password is correct */

                        /* nonce *may* be client provided, but must still be unique */

                        /* if server/client nonce is acceptable (valid server or unused client) */
                        $nonceItem = $this->noncePool->getItem($payload->nonce);
                        if (($nonceItem->isHit() && $nonceItem->get()) || ! $nonceItem->isHit()) {
                            /* mark nonce as spent */
                            $nonceItem->set(false); /* invalid */
                            $nonceItem->expiresAfter(60); /* keep for 1 minute */
                            $this->noncePool->save($nonceItem);

                            /* password authentication successful, grant access and set response */
                            $this->logger->debug("successful login for: $cleanId");
                            $event->setResponse(new Response("hi $cleanId"));
                            return;
                        }
                    }
                }
            }

            /* x-preauth was set, but didn't succeed at authenticating
             * invalid payload, wrong token, spent nonce, etc */
            $json = $payload->json ?? true;

            // TODO track failures.. try to identify if is a duplicate request, in which case, don't
            // use rate-limiting symfony system... then update RejectListener to work the same way...

            /* hash the query, so we do not count duplicates
             * hitting refresh a few times should not lock you out */
            $ipKey = $this->utilities->makeCacheKey("ip_{$event->getRequest()->getClientIp()}");
            $failuresItem = $this->requestCache->getItem($ipKey);
            $failures = $failuresItem->get() ?? [];
            $failures[hash('xxh3', $data)] = true;
            $failuresItem->set($failures);
            $failuresItem->expiresAfter(count($failures) >= $this->config->limit())
                ? $this->config->limitTtl() : $this->config->limitTimeout()
            );
            $this->requestCache->save($failuresItem);

            if ($json) {
                $contentType = 'application/json';
                $content     = json_encode([
                    'message' => (count($failures) >= $this->config->limit()) ? 'I am a teapot' : 'Unsuccessful login attempt',
                    'nonce'   => $this->makeNonce(),
                ]);
            } else {
                $contentType = 'text/html';
                $content     = $this->twig->render('login.html.twig', [
                    'base_domain' => $this->utilities->baseDomain($event->getRequest()->getHost()),
                    'nonce_value' => $this->makeNonce(),
                ]);
            }

            $this->logger->debug("loggin failure for: {$event->getRequest()->getClientIp()}");
            $event->setResponse(new Response($content,
                Response::HTTP_UNAUTHORIZED,
                ["content-type" => $contentType]
            ));
        }
    }

    private function decode(string $data): ?Payload {
        /* convert the base64url into json string ($json could be false) */
        $json = base64_decode(str_pad(strtr($data, '-_', '+/'),
            strlen($data) % 4, '=', STR_PAD_RIGHT
        ), true);

        /* convert json into a payload (will return null if invalid) */
        if ($json) {
            return Payload::create(json_decode($json));
        }

        return null;
    }

    private function setCookie(string $id): Cookie {
        /* successful auth with token, store session and set the cookie */
        $ulid = new Ulid();
        $sessionCookie = $this->sessionCache->getItem(
            $this->utilities->makeCacheKey("cookie_$ulid")
        );
        if ($sessionCookie->isHit()) {
            /* it is supposed to be impossible to have collisions */
            $this->logger->error("successful login but ULID collision, aborting");
            // TODO maybe a pretty error page and/or json...
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
        }
        $sessionCookie->set($id);
        $sessionCookie->expiresAfter($this->config->cookieTtl());
        $this->sessionCache->save($sessionCookie);

        return Cookie::create(
            name: "__Host-Http-Preauth",
            value: $ulid->toString(),
            expire: time() + $this->config->cookieTtl(),
            path: '/',
            secure: true,
            httpOnly: true
        );
    }

    private function setIp(string $id, string $ip): void {
        /* successful auth with token, requested scope of ip (and ip access enabled) */
        $ipKey = $this->utilities->makeCacheKey("ip_$ip");

        $sessionIp = $this->sessionCache->getItem($ipKey);
        $sessionIp->set($id);
        $sessionIp->expiresAfter($this->config->ipTtl());
        $this->sessionCache->save($sessionIp);
    }
}

