<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\PasskeyCredential;
use Webauthn\CredentialRecord;

/**
 * Persistence for registered passkeys, backed by the `sessionCache` pool so that
 * credentials survive a container restart the same way sessions do.
 *
 * The identity is stored alongside the credential and is authoritative on
 * assertion: the `userHandle` a client returns is attacker-controlled and is
 * compared for consistency but never used to decide who is logging in.
 */
interface PasskeyCredentialStoreInterface
{
    /**
     * Every registered credential, for the login ceremony's `allowCredentials`.
     *
     * Credentials whose stored payload cannot be read are skipped rather than
     * failing the ceremony, so one bad entry cannot lock everyone out.
     *
     * @return PasskeyCredential[]
     */
    public function all(): array;

    /**
     * Look up a single credential by its raw credential id.
     */
    public function find(string $credentialId): ?PasskeyCredential;

    /**
     * Every credential belonging to one identity.
     *
     * @return PasskeyCredential[]
     */
    public function findByIdentity(string $identity): array;

    /**
     * Persist a newly registered credential.
     */
    public function save(PasskeyCredential $credential): void;

    /**
     * Record that a credential was just used, refreshing its counter.
     */
    public function updateUsage(CredentialRecord $record): void;

    /**
     * Forget a credential.
     *
     * @return bool true when a credential was removed
     */
    public function remove(string $credentialId): bool;

    public function count(): int;
}
