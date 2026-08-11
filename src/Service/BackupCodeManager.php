<?php

declare(strict_types=1);

namespace App\Service;

use App\MonitorCacheKeys;
use App\Trait\HasLoggerTrait;
use App\Trait\StringTrait;
use DateTimeImmutable;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use App\Trait\GetTotpTrait;

/** backup-codes are case‑insensitive alphanumeric strings
 * they are single-use and marked as used after successful authentication */
final readonly class BackupCodeManager implements BackupCodeInterface
{
    use GetTotpTrait;
    use HasLoggerTrait;
    use StringTrait;

    private const int DEFAULT_COUNT = 10;
    /* php base_convert() will break if given too long of an input */
    public const int MAX_LENGTH = 64;

    private CacheItemPoolInterface $sessionCache;

    /** @throws InvalidArgumentException */
    public function __construct(CacheItemPoolInterface $sessionCache)
    {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
    }

    /** generate a set of backup-codes and return them
     * @param int $count Number of codes to generate
     * @return string[] Generated backup codes
     * @throws InvalidArgumentException|Exception */
    public function generate(int $count = self::DEFAULT_COUNT): array
    {
        $length = min($this->getTotp()->getDigits() + 2, self::MAX_LENGTH);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            /* output is alphanumeric string of given length */
            $codes[] = strtolower(str_pad(substr(base_convert(bin2hex(
                random_bytes($length)
            ), 16, 36), 0, $length), $length, '0', STR_PAD_LEFT));
        }
        $this->saveCodes($codes);
        $this->logger->info("generated {$count} backup codes");
        return $codes;
    }

    /** @throws InvalidArgumentException */
    public function expire(): void
    {
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

    /** check if backup-code is valid and mark it as used
     * @param string $code Code supplied by the client
     * @return bool true if the code is valid and unused
     * @throws InvalidArgumentException */
    public function verifyAndConsume(string $code): bool
    {
        /* remove unallowed characters, since backup codes are case-insensitive alphanumeric */
        $backupKey = 'backup_' . preg_replace('/[^a-z0-9]+/', '', strtolower($code));
        $backupItem = $this->sessionCache->getItem($this->makeCacheKey($backupKey));
        $this->logger->debug("checking backup code '{$backupKey}': " . ($backupItem->isHit() ? 'HIT & ' : 'miss & ') . ($backupItem->get() ? 'VALID' : 'invalid'));
        if ($backupItem->isHit() && $backupItem->get()) {
            $this->logger->debug("valid backup code");
            /* mark backup code as spent */
            $backupItem->set(false); /* used */
            /* per PSR6, if no expiration is set, implementation may set a default,
             * we want this to keep forever, so a few hundred years should do it */
            $backupItem->expiresAt(DateTimeImmutable::createFromFormat(
                'Y-m-d',
                '2999-12-31'
            ));
            $this->sessionCache->save($backupItem);

            return true;
        }
        return false;
    }

    /** @throws InvalidArgumentException */
    private function saveCodes(array $codes): void
    {
        foreach ($codes as $code) {
            $backupItem = $this->sessionCache->getItem($this->makeCacheKey(strtolower("backup_$code")));
            /* mark backup code as ready */
            $backupItem->set(true);
            /* per PSR6, if no expiration is set, implementation may set a default,
             * we want this to keep forever, so a few hundred years should do it */
            $backupItem->expiresAt(DateTimeImmutable::createFromFormat(
                'Y-m-d',
                '2999-12-31'
            ));
            $this->sessionCache->saveDeferred($backupItem);
        }
        $this->sessionCache->commit();
    }
}
