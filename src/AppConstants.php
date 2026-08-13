<?php

declare(strict_types=1);

namespace App;

/**
 * Shared application constants.
 */
final class AppConstants
{
    /**
     * Far-future expiration date used for persistent cache items
     * (TOTP secrets, backup codes) that should effectively never expire.
     * Per PSR-6, if no expiration is set, the implementation may set a
     * default — we use this to be explicit.
     */
    public const string FAR_FUTURE_DATE = '2999-12-31';

    /**
     * Maximum length for user-supplied input fields (id, nonce, token).
     * Also used for cache key truncation.
     */
    public const int MAX_INPUT_LENGTH = 128;
}
