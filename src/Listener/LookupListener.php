<?php

namespace App\Listener;

use App\ConfigBag;
use App\Trait\GetTotpTrait;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final readonly class LookupListener {
    use GetTotpTrait;

    public function __construct(
        ConfigBag $config,
    ) {
        $this->config = $config;
    }

    #[AsEventListener(priority: 44)]
    public function onKernelResponse(ResponseEvent $event): void {
        /* if you enable a static secret, you may choose to also enable
         * a totp-lookup, which contains a special path, when successfully
         * authenticated, instead of returning "hi <your-name>" it returns
         * "code <totp-code>" */
        if ($this->config->staticSecret() && $this->config->lookupTotp()) {
            if (str_contains($event->getRequest()->getPathInfo(),
                    $this->config->lookupTotp()) &&
                $event->getResponse()->getStatusCode() === Response::HTTP_OK
            ) {
                $totp = $this->getTotp();
                $next = $totp->at(time() + $totp->getPeriod());
                $event->setResponse(new Response(
                    "next {$next}",
                    Response::HTTP_OK,
                    ['Content-Type' => 'text/plain']
                ));
            }
        }
    }
}
