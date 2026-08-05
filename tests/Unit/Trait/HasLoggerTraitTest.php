<?php
declare(strict_types=1);

namespace App\Tests\Unit\Trait;

use App\Trait\HasLoggerTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HasLoggerTraitTest extends TestCase {
    use HasLoggerTrait;

    public function testSetLogger(): void {
        $logger = $this->createStub(LoggerInterface::class);
        $this->setLogger($logger);
        self::assertSame($logger, $this->logger);
    }
}
