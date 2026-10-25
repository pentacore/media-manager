<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum BenchmarkConversationMode: string
{
    use EnumUtils;

    case Context = 'context';
    case Scripted = 'scripted';

    public function label(): string
    {
        return match ($this) {
            self::Context => 'Recorded replies as context',
            self::Scripted => 'Candidate answers every turn',
        };
    }
}
