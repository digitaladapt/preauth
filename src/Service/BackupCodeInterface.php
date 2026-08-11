<?php

namespace App\Service;

use Exception;
use Psr\Cache\InvalidArgumentException;

/** backup-codes are case‑insensitive alphanumeric strings
 * they are single-use and marked as used after successful authentication */
interface BackupCodeInterface
{
    /** generate a set of backup-codes and return them
     * @param int $count Number of codes to generate
     * @return string[] Generated backup codes
     * @throws InvalidArgumentException|Exception */
    public function generate(int $count = 0): array;

    /** @throws InvalidArgumentException */
    public function expire(): void;

    /** check if backup-code is valid and mark it as used
     * @param string $code Code supplied by the client
     * @return bool true if the code is valid and unused
     * @throws InvalidArgumentException */
    public function verifyAndConsume(string $code): bool;
}
