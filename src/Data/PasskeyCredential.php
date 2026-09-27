<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use Webauthn\CredentialRecord;

/**
 * A stored passkey: the WebAuthn credential plus the application metadata that
 * ties it to a visitor of this gateway.
 *
 * The credential itself carries the public key and signature counter; the
 * metadata here records *whose* passkey it is and when it was used. The identity
 * is deliberately read from this record on assertion rather than from the value
 * the client returns, which is never trusted.
 */
final readonly class PasskeyCredential
{
    public function __construct(
        public CredentialRecord $record,
        /** The session id this passkey authenticates, as typed at registration. */
        public string $identity,
        /** Operator-facing description, shown so a user can tell their keys apart. */
        public string $label,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {
    }

    /**
     * A copy of this credential with the signature counter and last-used time
     * refreshed after a successful assertion.
     *
     * The counter is only ever observed, never enforced — many passkeys report a
     * constant zero, so treating it as a clone signal would lock users out.
     */
    public function withUsage(CredentialRecord $updated, DateTimeImmutable $usedAt): self
    {
        return new self(
            $updated,
            $this->identity,
            $this->label,
            $this->createdAt,
            $usedAt,
        );
    }
}
