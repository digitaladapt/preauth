<?php
declare(strict_types=1);

namespace App\Trait;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

trait MakeNonceTrait {
    protected CacheItemPoolInterface $noncePool;
    protected LoggerInterface        $logger;

    protected function makeNonce(int $retries = 3): string {
        /* 15 bytes neatly fits in base64 (no trailing "=") */
        $nonce = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
        $nonceItem = $this->noncePool->getItem($nonce);
        if ($nonceItem->isHit()) {
            if ($retries < 1) {
                $this->logger->error("failed to generate unique nonce after multiple attempts, aborting");
                // TODO maybe a pretty error page and/or json...
                throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
            }
            return $this->makeNonce($retries - 1);
        }
        $nonceItem->set(true); /* valid */
        $nonceItem->expiresAfter(60); /* keep for 1 minute */
$this->logger->debug("added nonce: '$nonce'");
        $this->noncePool->save($nonceItem);
        return $nonce;
    }
}

