<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\PasskeyCredential;
use Throwable;

/**
 * Both WebAuthn ceremonies, expressed without any library types in the
 * signature so callers never depend on webauthn-lib directly.
 */
interface PasskeyInterface
{
    /**
     * Step 1 of the login ceremony: issue a challenge and the credential list.
     *
     * @return array{publicKey: mixed, ceremonyId: string}
     *
     * @throws Throwable
     */
    public function beginLogin(): array;

    /**
     * Step 3 of the login ceremony: verify an assertion.
     *
     * Null means "not authenticated", and the caller must not distinguish
     * between the possible causes.
     *
     * @param array<string,mixed> $body
     *
     * @throws Throwable
     */
    public function finishLogin(array $body): ?PasskeyCredential;

    /**
     * Step 1 of the registration ceremony. Only reachable once the caller has
     * already authenticated with a TOTP code.
     *
     * @return array{publicKey: mixed, ceremonyId: string}
     *
     * @throws Throwable
     */
    public function beginRegistration(string $identity): array;

    /**
     * Step 3 of the registration ceremony: verify an attestation and store the
     * credential.
     *
     * Null means "not registered".
     *
     * @param array<string,mixed> $body
     *
     * @throws Throwable
     */
    public function finishRegistration(array $body): ?PasskeyCredential;
}
