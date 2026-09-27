<?php

declare(strict_types=1);

namespace App\Tests\Unit\CacheWarmer;

use App\CacheWarmer\PasskeyConfigurationWarmer;
use App\Exception\PasskeyConfigurationException;
use App\Service\PasskeyPolicyInterface;
use PHPUnit\Framework\TestCase;

/**
 * The warmer is the mechanism that turns a bad passkey configuration into a
 * failed deployment rather than a broken feature (D1/D4, plan §4.2).
 */
final class PasskeyConfigurationWarmerTest extends TestCase
{
    public function test_it_delegates_the_configuration_check(): void
    {
        $policy = $this->createMock(PasskeyPolicyInterface::class);
        $policy->expects(self::once())->method('assertConfigurationIsUsable');

        $warmer = new PasskeyConfigurationWarmer($policy);

        self::assertSame([], $warmer->warmUp('/tmp/cache'));
    }

    public function test_it_is_not_optional(): void
    {
        /* an optional warmer can be skipped, which would defeat the check */
        $warmer = new PasskeyConfigurationWarmer($this->createStub(PasskeyPolicyInterface::class));

        self::assertFalse($warmer->isOptional());
    }

    public function test_it_propagates_a_configuration_failure(): void
    {
        $policy = $this->createStub(PasskeyPolicyInterface::class);
        $policy->method('assertConfigurationIsUsable')
            ->willThrowException(new PasskeyConfigurationException('nope'));

        $this->expectException(PasskeyConfigurationException::class);
        (new PasskeyConfigurationWarmer($policy))->warmUp('/tmp/cache');
    }
}
