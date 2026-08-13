<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Controls what value is sent in the Remote-User header on auth success.
 */
enum RemoteUserMode: string
{
    /** Send the session id (current/default behaviour). */
    case Session = 'session';

    /** Send a fixed static string for all authenticated requests. */
    case Static = 'static';

    /** Look up the session id in a configured map and send the mapped value. */
    case Mapped = 'mapped';

    /** Do not send the Remote-User header at all. */
    case None = 'none';
}
