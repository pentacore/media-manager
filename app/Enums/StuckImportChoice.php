<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * What to do with a stuck Sonarr/Radarr import.
 */
enum StuckImportChoice: string
{
    use EnumUtils;

    case Import = 'import';
    case Remove = 'remove';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'import',
            self::Remove => 'remove',
            self::Manual => 'needs a human',
        };
    }

    /**
     * The option text the classifier chooses between, taken from the
     * DecisionAgent's stuck-import rules.
     */
    public function description(): string
    {
        return match ($this) {
            self::Import => 'Import it: the files map and the only rejections are benign, such as "matched by series id" or "automatic import is not possible".',
            self::Remove => 'Remove it: the blocking rejection says the release is not an upgrade, or not a Custom Format upgrade, for the existing file(s).',
            self::Manual => 'Leave it for a human: nothing maps, the rejections are mixed, or the right action is unclear.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(static fn (self $choice): string => $choice->value, self::cases()),
            array_map(static fn (self $choice): string => $choice->description(), self::cases()),
        );
    }
}
