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

    /**
     * Marks a response as WebAuthn ceremony output.
     *
     * A ceremony reply is the only 2xx this application returns straight to a
     * browser — every other 2xx is consumed by the reverse proxy's forward_auth
     * check. So it is the only one that needs the no-store treatment, and this
     * marker is how `SecurityHeadersListener` recognises it without the caching
     * policy being duplicated at each site that produces one.
     *
     * It lives here rather than on a listener because both `PasskeyListener`
     * (finish) and `LoginManager` (the registration hand-off) produce them.
     */
    public const string PASSKEY_CEREMONY_MARKER = 'X-Preauth-Ceremony';
}
