<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum SubtitleRuleStrength: string
{
    use EnumUtils;

    case Guarantee = 'guarantee';
    case StrongEvidence = 'strong_evidence';
    case Preference = 'preference';

    public function confidence(): ?int
    {
        return match ($this) {
            self::Guarantee => 98,
            self::StrongEvidence => 85,
            self::Preference => null,
        };
    }
}
