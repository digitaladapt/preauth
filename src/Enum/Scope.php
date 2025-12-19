<?php
declare(strict_types=1);

namespace App\Enum;

enum Scope: string {
    case Cookie = 'cookie';
    case Ip     = 'ip';
    case None   = 'none';
}

