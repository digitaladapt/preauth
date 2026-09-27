<?php

declare(strict_types=1);

namespace App\Service;

use App\Trait\StringTrait;
use DateTimeImmutable;
use Exception;
use Override;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Short-lived server-side state for an in-flight WebAuthn ceremony.
 *
 * **The client's copy of the challenge is never trusted.** Starting a ceremony
 * issues the challenge *and* a separate opaque `ceremonyId`; only the
 * server-side record keyed by that id is authoritative at `finish`. A client
 * that swaps in its own challenge is therefore comparing against a value the
 * server never issued.
 *
 * **Single-use.** {@see consume()} deletes the record *before* the caller
 * verifies anything, so a failed or replayed `finish` cannot be retried against
 * the same challenge.
 *
 * **Deliberately not persisted.** This uses the `nonceCache` pool, which is APCu
 * and stays in memory. A ceremony that does not complete within its TTL *should*
 * evaporate — persisting it would only widen the replay window across restarts.
 */
final readonly class PasskeyCeremonyStore implements PasskeyCeremonyStoreInterface
{
    use StringTrait;

    private const string PREFIX = 'passkey_cer_';

    /** Ceremonies live 5 minutes: a user has to interact with a biometric prompt. */
    public const int CEREMONY_TTL = 300;

    /** 15 bytes fits neatly into a base64url string without padding. */
    private const int CEREMONY_ID_BYTES = 15;

    /** 32-byte challenge, per WebAuthn's recommendation. */
    private const int CHALLENGE_BYTES = 32;

    public function __construct(
        #[Target('nonceCache')] private CacheItemPoolInterface $nonceCache,
    ) {
    }

    /**
     * @return array{ceremonyId: string, challenge: string}
     *
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Override]
    public function startLogin(): array
    {
        /* @var array{ceremonyId: string, challenge: string} */
        return $this->store(PasskeyCeremonyStoreInterface::TYPE_LOGIN, [
            'challenge' => random_bytes(self::CHALLENGE_BYTES),
        ]);
    }

    /**
     * @return array{ceremonyId: string, challenge: string, userHandle: string}
     *
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Override]
    public function startRegistration(string $identity): array
    {
        /* @var array{ceremonyId: string, challenge: string, userHandle: string} */
        return $this->store(PasskeyCeremonyStoreInterface::TYPE_REGISTER, [
            'challenge' => random_bytes(self::CHALLENGE_BYTES),
            'identity' => $identity,
            /* Derived from the identity rather than taken from the client, so a
             * caller cannot register a credential against an identity it did not
             * authenticate as. Fixed length, stable per identity, and it does not
             * leak the label into the authenticator. */
            'userHandle' => hash('sha256', $identity, true),
        ]);
    }

    /**
     * Read and destroy a ceremony, returning its record.
     *
     * Returns null when the id is unknown, expired, already consumed, or was
     * started for the other ceremony type. The caller cannot distinguish these
     * cases, and does not need to.
     *
     * @return array{challenge: string, identity?: string, userHandle?: string}|null
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public function consume(string $ceremonyId, string $expectedType): ?array
    {
        $key = $this->key($ceremonyId);
        $item = $this->nonceCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();

        /* delete before validating: a malformed or replayed record must not be
         * usable again even if the checks below reject it */
        $this->nonceCache->deleteItem($key);

        if (!\is_array($payload)
            || ($payload['type'] ?? null) !== $expectedType
            || !isset($payload['challenge'])
            || !\is_string($payload['challenge'])
        ) {
            return null;
        }

        /** @var array{challenge: string, identity?: string, userHandle?: string} $payload */
        $payload = array_filter(
            $payload,
            static fn (string $key): bool => \in_array($key, ['challenge', 'identity', 'userHandle'], true),
            \ARRAY_FILTER_USE_KEY,
        );

        return $payload;
    }

    /**
     * @param array<string,string> $payload
     *
     * @return array<string,string>
     *
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function store(string $type, array $payload): array
    {
        $ceremonyId = rtrim(strtr(base64_encode(random_bytes(self::CEREMONY_ID_BYTES)), '+/', '-_'), '=');

        $item = $this->nonceCache->getItem($this->key($ceremonyId));
        $item->set([
            'type' => $type,
            ...$payload,
            'createdAt' => new DateTimeImmutable()->format(\DATE_ATOM),
        ]);
        $item->expiresAfter(self::CEREMONY_TTL);
        $this->nonceCache->save($item);

        return ['ceremonyId' => $ceremonyId, ...$payload];
    }

    /**
     * The ceremony id is hashed rather than passed through `makeCacheKey()`,
     * because that sanitises the base64url alphabet into `_` and is therefore
     * not injective — two distinct ids could collide on one cache slot.
     *
     * @throws InvalidArgumentException
     */
    private function key(string $ceremonyId): string
    {
        return $this->makeCacheKey(self::PREFIX.hash('sha256', $ceremonyId));
    }
}
