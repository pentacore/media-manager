<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum ChatTemplateVariableType: string
{
    use EnumUtils;

    case Text = 'text';
    case Number = 'number';
    case Choice = 'choice';
    case Series = 'series';
    case Movie = 'movie';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Choice => 'Choice',
            self::Series => 'Series',
            self::Movie => 'Movie',
        };
    }

    /**
     * Series and movie variables are picked from the local library index.
     */
    public function isLibrary(): bool
    {
        return $this === self::Series || $this === self::Movie;
    }

    /**
     * A library default would go stale when the title leaves the library.
     */
    public function supportsDefault(): bool
    {
        return ! $this->isLibrary();
    }

    public function requiresOptions(): bool
    {
        return $this === self::Choice;
    }
}
