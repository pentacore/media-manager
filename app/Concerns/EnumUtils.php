<?php

namespace App\Concerns;

use Generator;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

trait EnumUtils
{
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function array(): array
    {
        return array_combine(self::values(), self::names());
    }

    public static function validationRule(): In
    {
        return Rule::in(self::values());
    }

    /**
     * @return Generator<string, static>
     */
    public static function iterator(): Generator
    {
        foreach (self::cases() as $case) {
            yield $case->name => $case;
        }
    }

    public function asSlug($separator = '-', $language = 'en', $dictionary = ['@' => 'at']): string
    {
        return new Stringable($this->value)->slug($separator, $language, $dictionary)->toString();
    }

    public static function mapForSelect(bool $withNull = false): array
    {
        $arr = [];
        if ($withNull) {
            $arr[] = ['name' => 'None', 'value' => null];
        }

        $values = array_map(static fn ($name, $value): array => ['name' => ucwords(str_replace('_', ' ', $value)), 'value' => $value], self::names(), self::values());
        usort($values, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_merge($arr, $values);
    }

    public static function commaSeparatedValues(): string
    {
        return implode(',', self::values());
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function equals(int|string $value): bool
    {
        return $this->value === $value;
    }
}
