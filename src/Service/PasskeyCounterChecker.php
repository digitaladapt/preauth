<?php

declare(strict_types=1);

namespace App\Service;

use Override;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;

/**
 * Signature-counter policy for passkey assertions.
 *
 * **Why this replaces the library default.** `ThrowExceptionIfInvalid` (the
 * default wired up by `CeremonyStepManagerFactory`) requires the reported
 * counter to be *strictly greater* than the stored one. That is wrong for the
 * passkeys this feature targets: platform passkeys are synchronised through the
 * OS keychain, and a synchronised authenticator reports a constant `0` forever
 * (the spec permits it, and the multi-device design effectively requires it).
 * Measured against the installed library:
 *
 *   stored 0, reported 0  -> `ThrowExceptionIfInvalid` throws `CounterException`
 *
 * so with the default checker a brand-new synced passkey fails on its *first*
 * login — and only on real hardware, never in a unit test that increments the
 * counter. That is the worst possible failure shape, so the default is not used.
 *
 * **What is kept.** A counter that goes *backwards* still fails. That is the one
 * signal the counter can carry (a cloned authenticator replaying an older
 * assertion), and rejecting it costs nothing because a genuine synchronised
 * passkey only ever reports the same value or a larger one.
 *
 * Note this is defence in depth and not relied upon for security: a
 * synchronised passkey's counter carries no clone signal at all, which is why
 * `SECURITY.md` records that clone detection is explicitly not a property this
 * feature claims. The real protections are per-credential challenge binding,
 * origin/RP-ID checks, and the user-verification requirement.
 */
final readonly class PasskeyCounterChecker implements CounterChecker
{
    /**
     * @throws CounterException when the reported counter moves backwards
     */
    #[Override]
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($currentCounter < $credentialRecord->counter) {
            throw CounterException::create(
                $currentCounter,
                $credentialRecord->counter,
                'The signature counter moved backwards, which can indicate a cloned authenticator.',
            );
        }
    }
}
