<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * User-verification requirement passed to the authenticator at passkey time.
 *
 * Mirrors the WebAuthn `userVerification` option without leaking the library's
 * constants into application configuration.
 */
enum UserVerification: string
{
    /** Require a biometric/PIN check (default). */
    case Required = 'required';

    /** Ask for it, but allow a plain user-presence tap to succeed. */
    case Preferred = 'preferred';

    /** Never prompt for verification; presence alone is enough. */
    case Discouraged = 'discouraged';

    /**
     * Parse a configured value, falling back to the safest option.
     *
     * An unrecognised value must never silently weaken the requirement, so the
     * fallback is the strictest case rather than the most permissive one.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom(trim($value)) ?? self::Required;
    }
}
