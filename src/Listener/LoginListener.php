<?php

declare(strict_types=1);

namespace App\Listener;

use App\ConfigBag;
use App\Data\Payload;
use App\Service\DomainInterface;
use App\Service\LoginInterface;
use App\Trait\CookieNameTrait;
use App\Trait\HasLoggerTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final readonly class LoginListener
{
    use CookieNameTrait;
    use HasLoggerTrait;
    use MakeNonceTrait;
    use StringTrait;

    private RateLimiterFactoryInterface $rateLimiter;

    public function __construct(
        private Environment                 $twig,
        #[Target('login_limiter')] RateLimiterFactoryInterface $rateLimiter,
        private DomainInterface             $domainManager,
        private LoginInterface              $loginManager,
        private ConfigBag                   $config,
    ) {
        $this->rateLimiter  = $rateLimiter;
    }

    /** @throws InvalidArgumentException|LoaderError|RuntimeError|SyntaxError */
    #[AsEventListener(priority: 66)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $payload  = null;
        $response = null;

        if ($event->getRequest()->headers->has($this->headerName())) {
            /* if request contains our "X-Preauth" header */
            $data = $event->getRequest()->headers->get($this->headerName());
            $payload = Payload::decode($data);
        } elseif ($event->getRequest()->isMethod(Request::METHOD_POST) &&
            $this->domainManager->getAuthSubdomain() === $event->getRequest()->getHost()
        ) {
            /* if request is a POST to the auth-subdomain */
            $payload = Payload::load($event->getRequest()->getPayload());
        } else {
            /* no login attempt detected */
            return;
        }

        if ($payload) {
            /* user sent a valid payload, check it */
            $response = $this->loginManager->checkToken($payload, $event->getRequest());

            /* token or backup-code authentication was successful */
            if ($response) {
                $event->setResponse($response);
                return;
            }
        }

        /* login attempted but unsuccessful, log and block if needed */
        $limitReached = $this->logFailure($event->getRequest());

        $this->logger->debug("logging failure for: {$event->getRequest()->getClientIp()}");
        $event->setResponse($this->makeFailedResponse(
            $limitReached,
            $payload->json ?? true,
            $event->getRequest()->getHost(),
            $this->makeCacheKey($payload ? $payload->id : '')
        ));
    }

    private function logFailure(Request $request): bool
    {
        $limiter = $this->rateLimiter->create($request->getClientIp());
        return ($limiter->consume(1)->getRemainingTokens() < 1);
    }

    /** @throws InvalidArgumentException|RuntimeError|SyntaxError|LoaderError */
    private function makeFailedResponse(bool $limited, bool $json, string $host, string $username): Response
    {
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
            'post'    => $this->domainManager->getAuthSubdomain() === $host,
            'username' => $username,
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
