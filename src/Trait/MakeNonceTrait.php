<?php
declare(strict_types=1);

namespace App\Trait;

use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait MakeNonceTrait {
    /* 15 bytes neatly fits in base64 */
    private const NONCE_LENGTH = 15;
    private const NONCE_TTL = 120;

    protected readonly CacheItemPoolInterface $noncePool;
    protected readonly LoggerInterface        $logger;

    /** @throws InvalidArgumentException|Exception */
    protected function makeNonce(int $retries = 3): string {
        /* convert raw binary into base64url */
        $nonce = rtrim(strtr(base64_encode(random_bytes(
            static::NONCE_LENGTH
        )), '+/', '-_'), '=');
        $nonceItem = $this->noncePool->getItem($nonce);

        if ($nonceItem->isHit()) {
            if ($retries < 1) {
                $this->logger->error("aborting: multiple nonce collisions");
                throw new HttpException(
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    'Internal Server Error'
                );
            }
            /* managed to have a collision, try again */
            return $this->makeNonce($retries - 1);
        }

        $nonceItem->set(true); /* valid */
        $nonceItem->expiresAfter(static::NONCE_TTL);
        $this->logger->debug("added nonce: $nonce");
        $this->noncePool->save($nonceItem);
        return $nonce;
    }
}
