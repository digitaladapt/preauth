<?php
declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel {
    use MicroKernelTrait;

    private PersistCache $persistCache;

    public function boot(): void {
        parent::boot();

        $this->persistCache = $this->container->get(PersistCache::class);
        $this->persistCache->boot();
    }

    public function terminate(Request $request, Response $response): void {
        $this->persistCache->persist();

        parent::terminate($request, $response);
    }
}
