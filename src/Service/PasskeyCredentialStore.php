<?php

declare(strict_types=1);

namespace App\Service;

use App\AppConstants;
use App\Data\PasskeyCredential;
use App\MonitorCacheKeys;
use App\Trait\StringTrait;
use DateTimeImmutable;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Webauthn\CredentialRecord;

/**
 * @see PasskeyCredentialStoreInterface
 *
 * **Persistence.** The pool is wrapped in a {@see MonitorCacheKeys}, exactly
 * as `LoginManager` and `BackupCodeManager` do. Without that wrapper the
 * credentials would live only in the APCu-side pool and disappear on the next
 * container restart, because `PersistCache::persist()` only flushes keys that a
 * monitor recorded. `PasskeyCredentialStoreTest` asserts visibility in the
 * underlying persistent pool, not just the wrapped one.
 *
 * **Layout.**
 *   passkey_cred_<key(credentialId)> -> serialized credential + metadata
 *   passkey_index                    -> credentialId => {identity, label, createdAt}
 *
 * The index exists so the login page can build `allowCredentials` without
 * enumerating the whole key space, and so a corrupt credential cannot make the
 * list disappear entirely.
 */
final readonly class PasskeyCredentialStore implements PasskeyCredentialStoreInterface
{
    use StringTrait;

    private const string PREFIX = 'passkey_cred_';
    private const string INDEX_KEY = 'passkey_index';

    private MonitorCacheKeys $sessionCache;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        #[Target('sessionCache')] CacheItemPoolInterface $sessionCache,
        private PasskeyCeremonyFactory $ceremonyFactory,
    ) {
        $this->sessionCache = new MonitorCacheKeys($sessionCache);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function all(): array
    {
        $credentials = [];
        foreach ($this->credentialIds() as $credentialId) {
            $credential = $this->find($credentialId);
            if (null !== $credential) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function find(string $credentialId): ?PasskeyCredential
    {
        $item = $this->sessionCache->getItem($this->credentialKey($credentialId));
        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();
        if (!\is_array($payload)
            || !isset($payload['record'], $payload['identity'], $payload['label'], $payload['createdAt'])
        ) {
            return null;
        }

        $record = $this->ceremonyFactory->deserializeCredential((string) $payload['record']);
        if (null === $record) {
            return null;
        }

        /* Guard against an index/record mismatch: the stored record decides which
         * credential id it answers to. */
        if (!hash_equals($record->publicKeyCredentialId, $credentialId)) {
            return null;
        }

        return new PasskeyCredential(
            $record,
            (string) $payload['identity'],
            (string) $payload['label'],
            new DateTimeImmutable((string) $payload['createdAt']),
            isset($payload['lastUsedAt']) ? new DateTimeImmutable((string) $payload['lastUsedAt']) : null,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function findByIdentity(string $identity): array
    {
        $matches = [];
        foreach ($this->all() as $credential) {
            if (hash_equals($credential->identity, $identity)) {
                $matches[] = $credential;
            }
        }

        return $matches;
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function save(PasskeyCredential $credential): void
    {
        $credentialId = $credential->record->publicKeyCredentialId;

        $item = $this->sessionCache->getItem($this->credentialKey($credentialId));
        $item->set([
            'record' => $this->ceremonyFactory->serializeCredential($credential->record),
            'identity' => $credential->identity,
            'label' => $credential->label,
            'createdAt' => $credential->createdAt->format(\DATE_ATOM),
            'lastUsedAt' => $credential->lastUsedAt?->format(\DATE_ATOM),
        ]);
        /* per PSR6, if no expiration is set, implementation may set a default,
         * we want this to keep forever, so a few hundred years should do it */
        $item->expiresAt($this->forever());
        $this->sessionCache->save($item);

        $this->writeIndexEntry($credentialId, [
            'identity' => $credential->identity,
            'label' => $credential->label,
            'createdAt' => $credential->createdAt->format(\DATE_ATOM),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function updateUsage(CredentialRecord $record): void
    {
        $existing = $this->find($record->publicKeyCredentialId);
        if (null === $existing) {
            return;
        }

        $this->save($existing->withUsage($record, new DateTimeImmutable()));
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function remove(string $credentialId): bool
    {
        $index = $this->readIndex();
        $existed = \array_key_exists($credentialId, $index);

        unset($index[$credentialId]);
        $this->writeIndex($index);

        return $this->sessionCache->deleteItem($this->credentialKey($credentialId)) || $existed;
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function count(): int
    {
        return \count($this->credentialIds());
    }

    /**
     * Credential ids from the index only.
     *
     * Deliberately not `getKeys()`: keys are truncated to a fixed length by
     * `makeCacheKey()`, so enumerating them cannot reliably recover a full
     * credential id. The index stores the ids verbatim.
     *
     * @return string[]
     *
     * @throws InvalidArgumentException
     */
    private function credentialIds(): array
    {
        return array_keys($this->readIndex());
    }

    /**
     * @return array<string,array{identity: string, label: string, createdAt: string}>
     *
     * @throws InvalidArgumentException
     */
    private function readIndex(): array
    {
        $item = $this->sessionCache->getItem(self::INDEX_KEY);
        $index = $item->isHit() ? $item->get() : null;

        return \is_array($index) ? $index : [];
    }

    /**
     * @param array<string,array{identity: string, label: string, createdAt: string}> $index
     *
     * @throws InvalidArgumentException
     */
    private function writeIndex(array $index): void
    {
        $item = $this->sessionCache->getItem(self::INDEX_KEY);
        $item->set($index);
        $item->expiresAt($this->forever());
        $this->sessionCache->save($item);
    }

    /**
     * @param array{identity: string, label: string, createdAt: string} $entry
     *
     * @throws InvalidArgumentException
     */
    private function writeIndexEntry(string $credentialId, array $entry): void
    {
        $index = $this->readIndex();
        $index[$credentialId] = $entry;
        $this->writeIndex($index);
    }

    /** Credentials and the index are kept indefinitely; only removal clears them. */
    private function forever(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('Y-m-d', AppConstants::FAR_FUTURE_DATE);
    }

    /**
     * Cache key for one credential id.
     *
     * The id is hashed rather than passed through `makeCacheKey()`, because that
     * sanitises the base64url alphabet into a single `_` and is therefore not
     * injective: "abc-def" and "abc_def" both become "abc_def", so two distinct
     * credentials could share one cache slot and one of them would silently
     * overwrite the other. A hash is injective for practical purposes and keeps
     * the key within the allowed character set.
     */
    private function credentialKey(string $credentialId): string
    {
        return self::PREFIX.hash('sha256', $credentialId);
    }
}
