<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Server-side storage for in-flight WebAuthn ceremonies.
 *
 * Kept separate from {@see PasskeyManager} so the security-critical properties —
 * the challenge is server-authoritative and single-use — are testable without
 * constructing a real authenticator response.
 */
interface PasskeyCeremonyStoreInterface
{
    public const string TYPE_LOGIN = 'login';

    public const string TYPE_REGISTER = 'register';

    /**
     * Start a login (assertion) ceremony.
     *
     * @return array{ceremonyId: string, challenge: string}
     */
    public function startLogin(): array;

    /**
     * Start a registration (attestation) ceremony for an already-authenticated
     * identity.
     *
     * @return array{ceremonyId: string, challenge: string, userHandle: string}
     */
    public function startRegistration(string $identity): array;

    /**
     * Read and destroy a ceremony.
     *
     * Returns null when the id is unknown, expired, already consumed, or was
     * started for the other ceremony type.
     *
     * @return array{challenge: string, identity?: string, userHandle?: string}|null
     */
    public function consume(string $ceremonyId, string $expectedType): ?array;
}
