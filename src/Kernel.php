<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel {
    use MicroKernelTrait;

    private PersistCache $persistCache;

    /** @throws InvalidArgumentException */
    public function boot(): void {
        parent::boot();

        $this->persistCache = $this->container->get(PersistCache::class);
        $this->persistCache->boot();
    }

    /** @throws InvalidArgumentException */
    public function terminate(Request $request, Response $response): void {
        $this->persistCache->persist();

        parent::terminate($request, $response);
    }
}
