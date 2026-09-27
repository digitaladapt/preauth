<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when passkeys are enabled in a configuration that cannot support them.
 *
 * This is deliberately fatal: the alternative is a feature that appears to be
 * switched on but cannot complete a single ceremony, which surfaces to the user
 * as "my passkey stopped working" rather than as a deployment error.
 *
 * Raised during cache warm-up so that a misconfigured container fails to start
 * instead of failing later, in a browser.
 */
final class PasskeyConfigurationException extends RuntimeException
{
}
