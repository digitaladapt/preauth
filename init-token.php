<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OTPHP\TOTP;

$token = getenv('PREAUTH_TOKEN');

// generate a new token, if not specified
if ( ! $token) {
    $token = TOTP::generate()->getSecret();
    error_log("ERROR: PREAUTH_TOKEN is not set, generating random TOTP token:\n'$token'\nupdate your config or it will be regenerated when you restart.");
}

echo $token;

