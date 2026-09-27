<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasskeyCounterChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\Counter\ThrowExceptionIfInvalid;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The counter policy is the difference between "works on real hardware" and
 * "works only in tests", so both halves of it are pinned here: the behaviour we
 * deliberately allow, and the behaviour we deliberately still refuse.
 */
final class PasskeyCounterCheckerTest extends TestCase
{
    private function makeRecord(int $counter): CredentialRecord
    {
        return CredentialRecord::create(
            random_bytes(32),
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            'COSE_PUBLIC_KEY_BYTES',
            'user-handle',
            $counter,
            null,
            true,
            false,
            true,
        );
    }

    /**
     * The regression this class exists for: a synchronised passkey reports 0
     * forever, so the first login of a brand-new credential must succeed.
     */
    public function test_a_constant_zero_counter_is_accepted(): void
    {
        $checker = new PasskeyCounterChecker();
        $record = $this->makeRecord(0);

        $checker->check($record, 0);
        $checker->check($record, 0);

        /* reaching this point without an exception is the assertion */
        self::assertSame(0, $record->counter);
    }

    /**
     * Documents *why* the library default cannot be used: it rejects the exact
     * scenario above. If a future library version relaxes this, the test fails
     * and the custom checker can be reconsidered rather than kept by habit.
     */
    public function test_the_library_default_would_reject_a_constant_zero_counter(): void
    {
        $this->expectException(CounterException::class);

        (new ThrowExceptionIfInvalid())->check($this->makeRecord(0), 0);
    }

    public function test_a_counter_that_moves_forward_is_accepted(): void
    {
        $checker = new PasskeyCounterChecker();
        $record = $this->makeRecord(5);

        $checker->check($record, 6);
        $checker->check($record, PHP_INT_MAX);

        self::assertSame(5, $record->counter);
    }

    public function test_a_counter_that_moves_backwards_is_rejected(): void
    {
        $checker = new PasskeyCounterChecker();
        $record = $this->makeRecord(5);

        $this->expectException(CounterException::class);

        $checker->check($record, 4);
    }

    /**
     * The exception carries both values, which the listener logs. Asserted so a
     * future refactor cannot quietly drop the diagnostic detail.
     */
    public function test_the_rejection_reports_both_counters(): void
    {
        $checker = new PasskeyCounterChecker();
        $record = $this->makeRecord(9);

        try {
            $checker->check($record, 3);
            self::fail('Expected a CounterException.');
        } catch (CounterException $exception) {
            self::assertSame(3, $exception->currentCounter);
            self::assertSame(9, $exception->authenticatorCounter);
        }
    }

    /**
     * The checker under test must differ from the library default, otherwise
     * wiring the default back in by accident would go unnoticed.
     */
    public function test_it_is_not_the_library_default(): void
    {
        self::assertNotInstanceOf(ThrowExceptionIfInvalid::class, new PasskeyCounterChecker());
    }
}
