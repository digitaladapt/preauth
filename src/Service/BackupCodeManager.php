<?php
declare(strict_types=1);

namespace App\Service;

use App\MonitorCacheKeys;
use App\Trait\StringTrait;
use DateTimeImmutable;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use App\Trait\GetTotpTrait;

/**
 * Manages generation and validation of single‑use backup codes.
 *
 * Backup codes are case‑insensitive alphanumeric strings whose length is
 * the length of the TOTP code plus two characters. They are stored in the
 * cache. Each code is marked as used after a successful authentication.
 */
final class BackupCodeManager {
    use GetTotpTrait;
    use StringTrait;

    private const DEFAULT_COUNT = 10;
    /* php base_convert() will break if given too long of an input */
    const MAX_LENGTH = 64;

    private CacheItemPoolInterface $sessionCache;

    /** @throws InvalidArgumentException */
    public function __construct(CacheItemPoolInterface $sessionCache) {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
    }

    /**
     * Generate a set of backup codes for a given user identifier.
     *
     * @param int $count Number of codes to generate
     * @return list<string> Generated backup codes
     * @throws InvalidArgumentException|Exception
     */
    public function generate(int $count = self::DEFAULT_COUNT): array {
        $length = min($this->getTotp()->getDigits() + 2, self::MAX_LENGTH);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            /* output is alphanumeric string of given length */
            $codes[] = str_pad(substr(base_convert(bin2hex(random_bytes($length)),
                    16, 36), 0, $length),
                $length, '0', STR_PAD_LEFT);
        }
        $this->saveCodes($codes);
        return $codes;
    }

    /** @throws InvalidArgumentException */
    public function expire(): void {
        $itemsToRemove = [];
        foreach ($this->sessionCache->getKeys() as $key) {
            if (str_starts_with($key, 'backup_')) {
                $itemsToRemove[] = $key;
            }
        }
        if (count($itemsToRemove) > 0) {
            $this->sessionCache->deleteItems($itemsToRemove);
        }
    }

    /**
     * Verify a backup code and, if valid, mark it as used.
     *
     * @param string $code Code supplied by the client
     * @return bool true if the code is valid and unused
     * @throws InvalidArgumentException
     */
    public function verifyAndConsume(string $code): bool {
        $backupItem = $this->sessionCache->getItem($this->makeCacheKey(strtolower("backup_$code")));
        if ($backupItem->isHit() && $backupItem->get()) {
            /* mark backup code as spent */
            $backupItem->set(false); /* used */
            /* per PSR6, if no expiration is set, implementation may set a default,
             * we want this to keep forever, so a few hundred years should do it */
            $backupItem->expiresAt(DateTimeImmutable::createFromFormat(
                'Y-m-d', '2999-12-31'
            ));
            $this->sessionCache->save($backupItem);

            return true;
        }
        return false;
    }

    /** @throws InvalidArgumentException */
    private function saveCodes(array $codes): void {
        foreach ($codes as $code) {
            $backupItem = $this->sessionCache->getItem($this->makeCacheKey(strtolower("backup_$code")));
            /* mark backup code as ready */
            $backupItem->set(true);
            /* per PSR6, if no expiration is set, implementation may set a default,
             * we want this to keep forever, so a few hundred years should do it */
            $backupItem->expiresAt(DateTimeImmutable::createFromFormat(
                'Y-m-d', '2999-12-31'
            ));
            $this->sessionCache->saveDeferred($backupItem);
        }
        $this->sessionCache->commit();
    }
}
