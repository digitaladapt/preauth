<?php
declare(strict_types=1);

namespace App\Trait;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

trait HasLoggerTrait {
    protected readonly LoggerInterface $logger;

    #[Required]
    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }
}
