<?php
declare(strict_types=1);

namespace App\Enum;

/** scope defines the context of how a session is persisted */
enum Scope: string {
    case Cookie = 'cookie';
    case Ip     = 'ip';
    case None   = 'none';
}
