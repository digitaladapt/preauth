<?php
declare(strict_types=1);

namespace App\Controller;

use App\ConfigBag;
use App\MonitorCacheKeys;
use App\Utilities;
use OTPHP\Factory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

final class PreauthController extends AbstractController {
    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    #[Route(path: '/{path<.+>?}', name: 'preauth', priority: 99)]
    public function index(
        LoggerInterface        $logger,
        CacheItemPoolInterface $sessionCache,
        CacheItemPoolInterface $requestCache,
        ConfigBag              $config,
        Utilities              $utilities,
        Request                $request,
        ?string                $path
    ): Response {
        /* check if they sent the preauth cookie, see: AcceptListener */
        /* check if they have made too many failed login attempts, see: RejectListener */

        /* if they have requested a file we have in our asset directory, then serve it,
         * but only if we are on the preauth domain, filenames are limited to safe
         * characters (we allow alphanumeric and "- _ . /" without "..") */
        if ($path && $config->assetsDir()) {
            /* host matches preauth, so we are allowed to serve assets */
            if ($utilities->buildDomain($config->subdomain(), $request->getHost()) ===
                $request->getHost()
            ) {
                $cleanPath = $utilities->cleanPath($config->assetsDir() . $path);
                if ($cleanPath && file_exists($cleanPath)) {
                    $logger->debug("sending static asset: $path");
                    return $this->file(
                        $cleanPath,
                        disposition: ResponseHeaderBag::DISPOSITION_INLINE
                    );
                }
            }
        }

        /* we must monitor keys, so we can enable persistence */
        $requestCache = new MonitorCacheKeys($requestCache);
        $sessionCache = new MonitorCacheKeys($sessionCache);

        // TODO need an unblock option, thinking a command, so you can do
        //  docker exec -it preauth preauth list-blocked     and
        //  docker exec -it preauth preauth unblock <ip-address>
        // TODO i think I can also move serve static asset into a kernel listener...
        // TODO move already-logged-in and already-blocked into a pre-controller
        //    kernel event listener
        // https://symfony.com/doc/7.3/event_dispatcher.html
        //    #before-filters-with-the-kernel-controller-event

        $ipKey = $utilities->makeCacheKey("ip_{$request->getClientIp()}");

        /* check if they came from allowed ip address */
        if ($sessionCache->hasItem($ipKey)) {
            /* request sent from ip with valid existing session */
            $id = $sessionCache->getItem($ipKey)->get();
            $logger->debug("has valid ip-session: $id");
            return new Response("hi $id");
        }


        // TODO move monitor cache to here... because everything above is readonly...

        // TODO has-login-query-fields could be another pre-controller kernel event listener

        /* check if they are attempting to log in right now */
        if ($request->query->has($config->query('id')) &&
            $request->query->has($config->query('token'))
        ) {
            $otp = Factory::loadFromProvisioningUri($config->totpUri(), $config->clock());
            /* they gave us a token, but it does not match totp nor static secret */
            if ( ! $otp->verify($request->query->get($config->query('token'))) &&
                ( ! $config->staticSecret() ||
                    $request->query->get($config->query('token')) !== $config->staticSecret()
                )
            ) {
                /* hash the query, so we do not count duplicates
                 * hitting refresh a few times should not lock you out */
                $failuresItem = $requestCache->getItem($ipKey);
                $failures = $failuresItem->get() ?? [];
                $failures[$utilities->hash($request->query)] = true;
                $failuresItem->set($failures);
                $failuresItem->expiresAfter(($failures >= $config->limit())
                    ? $config->limitTtl() : $config->limitTimeout()
                );
                $requestCache->save($failuresItem);

                if (count($failures) >= $config->limit()) {
                    $logger->debug("starting to block now: {$request->getClientIp()}");
                    return $this->render('error.html.twig', [
                        'base_domain' => $utilities->baseDomain($request->getHost()),
                    ], new Response(status: $config->teapot()
                        ? Response::HTTP_I_AM_A_TEAPOT : Response::HTTP_TOO_MANY_REQUESTS
                    ));
                }

                $logger->debug("login failure: {$request->getClientIp()}");
                return $this->render('login.html.twig', [
                    'base_domain' => $utilities->baseDomain($request->getHost()),
                    'return_value' => $request->query->get($config->query('return')) ?:
                        "{$request->getPathInfo()}{$request->getQueryString()}",
                    'has_error' => true,
                ], new Response(status: Response::HTTP_UNAUTHORIZED));
            }
            /* successful auth with token, store session and set the cookie */
            $cleanId = $utilities->makeCacheKey($request->query->get($config->query('id')));
            $ulid = new Ulid();
            $sessionCookie = $sessionCache->getItem($utilities->makeCacheKey("cookie_$ulid"));
            if ($sessionCookie->isHit()) {
                /* it is supposed to be impossible to have collisions */
                $logger->error("successful login but ULID collision aborting");
                // TOOD maybe a pretty error page
                return new Response('Internal Server Error',
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
            $sessionCookie->set($cleanId);
            $sessionCookie->expiresAfter($config->cookieTtl());
            $sessionCache->saveDeferred($sessionCookie);

            if ($config->ipTtl()) {
                $sessionIp = $sessionCache->getItem($ipKey);
                $sessionIp->set($cleanId);
                $sessionIp->expiresAfter($config->ipTtl());
                $sessionCache->saveDeferred($sessionIp);
            }

            $sessionCache->commit();

            /* successful login, reset rate-limit of their ip */
            $requestCache->deleteItem($ipKey);

            $redirect = $request->query->get($config->query('return')) ?: $config->sendTo();

            if ( ! $redirect) {
                /* just logged in with nowhere to go */
                $response = new Response("hi $cleanId");
                $response->headers->setCookie(Cookie::create(
                    $config->query('ulid'), $ulid->toString(),
                    time() + $config->cookieTtl(),
                    domain: $utilities->baseDomain($request->getHost()), secure: true
                ));
                $logger->debug("successful login with no return set: $cleanId");
                return $response;
            }

            $response = $this->redirect($redirect);
            $response->headers->setCookie(Cookie::create(
                $config->query('ulid'), $ulid->toString(),
                time() + $config->cookieTtl(),
                domain: $utilities->baseDomain($request->getHost()), secure: true
            ));
            $logger->debug("successful login redirecting back: $cleanId");
            return $response;
        }

        /* host matches where we would send them so show the preauth login page */
        if ($utilities->buildDomain($config->subdomain(), $request->getHost()) ===
            $request->getHost()
        ) {
            $logger->debug("presenting login page: {$request->getClientIp()}");
            return $this->render('login.html.twig', [
                'base_domain' => $utilities->baseDomain($request->getHost()),
                'return_value' => $request->query->get($config->query('return')) ?:
                    "{$request->getPathInfo()}{$request->getQueryString()}",
            ]);
        }

        /* needs to be sent to the login page */
        $query = http_build_query([
            $config->query('return') => $request->getUri(),
        ]);
        $logger->debug("elsewhere sending to login: {$request->getClientIp()}");
        $destination = $utilities->buildDomain($config->subdomain(), $request->getHost());
        return $this->redirect("https://$destination/?$query");
    }
}
