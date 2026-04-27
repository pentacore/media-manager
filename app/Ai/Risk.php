<?php

declare(strict_types=1);

namespace App\Ai;

enum Risk: string
{
    case Read = 'read';
    case SafeWrite = 'safe_write';
    case Destructive = 'destructive';
}
