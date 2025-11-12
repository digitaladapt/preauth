<?php
declare(strict_types=1);

$key = getenv('PREAUTH_KEY');

// generate a new key, if not specified
if ( ! $key) {
    $key = base64_encode(random_bytes(64));
    error_log("ERROR: PREAUTH_KEY is not set, generating a random session encryption key:\n'$key'\nupdate your config or it will be regenerated when you restart.");
}

echo $key;

