<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasskeyCeremonyFactory;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The factory is the single seam between the application and webauthn-lib, so
 * these tests pin the library behaviours the rest of the feature relies on.
 */
final class PasskeyCeremonyFactoryTest extends TestCase
{
    private function makeFactory(): PasskeyCeremonyFactory
    {
        return new PasskeyCeremonyFactory();
    }

    private function makeRecord(): CredentialRecord
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
            0,
            null,
            true,
            false,
            true,
        );
    }

    public function test_it_exposes_the_serializer(): void
    {
        self::assertInstanceOf(SerializerInterface::class, $this->makeFactory()->serializer());
    }

    /**
     * Only the `none` attestation format is registered — the deliberate choice
     * recorded in `SECURITY.md`. Registering more formats would not make
     * attestation verifiable; it would only accept statements nothing checks.
     */
    public function test_only_none_attestation_is_supported(): void
    {
        $manager = $this->makeFactory()->attestationStatementSupportManager();

        self::assertInstanceOf(AttestationStatementSupportManager::class, $manager);
        self::assertTrue($manager->has('none'));
        self::assertFalse($manager->has('packed'));
        self::assertFalse($manager->has('fido-u2f'));
        self::assertFalse($manager->has('tpm'));
        self::assertFalse($manager->has('android-key'));
        self::assertFalse($manager->has('apple'));
    }

    public function test_the_support_manager_has_the_none_support_registered(): void
    {
        $manager = $this->makeFactory()->attestationStatementSupportManager();

        self::assertInstanceOf(
            NoneAttestationStatementSupport::class,
            $manager->get('none'),
        );
    }

    public function test_credential_records_round_trip_through_storage(): void
    {
        $factory = $this->makeFactory();
        $record = $this->makeRecord();

        $restored = $factory->deserializeCredential($factory->serializeCredential($record));

        self::assertNotNull($restored);
        self::assertSame($record->publicKeyCredentialId, $restored->publicKeyCredentialId);
        self::assertSame($record->credentialPublicKey, $restored->credentialPublicKey);
        self::assertSame($record->userHandle, $restored->userHandle);
        self::assertSame($record->counter, $restored->counter);
        self::assertSame($record->transports, $restored->transports);
        self::assertSame($record->attestationType, $restored->attestationType);
        self::assertSame($record->backupEligible, $restored->backupEligible);
        self::assertSame($record->backupStatus, $restored->backupStatus);
        self::assertSame($record->uvInitialized, $restored->uvInitialized);
        self::assertSame($record->aaguid->__toString(), $restored->aaguid->__toString());
    }

    /**
     * A record is not JsonSerializable, so a plain json_encode() would silently
     * produce something that cannot be read back. The factory must not rely on
     * that path.
     */
    public function test_credential_records_are_not_naively_json_encodable(): void
    {
        self::assertNotInstanceOf(JsonSerializable::class, $this->makeRecord());
    }

    /**
     * Unreadable stored data degrades to null so a corrupt entry cannot produce
     * a 500 on the login page.
     */
    public function test_unreadable_stored_data_returns_null(): void
    {
        self::assertNull($this->makeFactory()->deserializeCredential('{not valid json'));
        self::assertNull($this->makeFactory()->deserializeCredential(''));
        self::assertNull($this->makeFactory()->deserializeCredential('{"unexpected":"shape"}'));
    }
}
