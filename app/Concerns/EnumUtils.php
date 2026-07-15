<?php

declare(strict_types=1);

namespace App\Concerns;

use Generator;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

trait EnumUtils
{
    /**
     * Returns an array of the enum case names.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Returns an array of the enum values.
     *
     * @return array<int, int|string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Returns an associative array of enum values as keys and their corresponding names as values.
     *
     * @return array<int|string, string>
     */
    public static function array(): array
    {
        return array_combine(self::values(), self::names());
    }

    /**
     * Returns a validation rule that checks if a value is one of the enum values.
     */
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

    /**
     * Converts the enum value to a URL-friendly slug.
     */
    public function asSlug($separator = '-', $language = 'en', $dictionary = ['@' => 'at']): string
    {
        return new Stringable($this->value)->slug($separator, $language, $dictionary)->toString();
    }

    /**
     * Returns an array of enum values and their corresponding names for use in a select dropdown.
     * If $withNull is true, the array will include a null value at index 0.
     *
     * @return array<int, array{name: string, value: int|string}>
     */
    public static function mapForSelect(bool $withNull = false, string $labelKey = 'name'): array
    {
        $arr = [];
        if ($withNull) {
            $arr[] = [$labelKey => 'None', 'value' => null];
        }

        $values = array_map(
            static fn ($case): array => [
                $labelKey => method_exists($case, 'label')
                    ? $case->label()
                    : Str::of($case->name)->snake()->replace('_', ' ')->ucfirst()->toString(),
                'value' => $case->value,
            ],
            self::cases()
        );

        usort(
            $values,
            static fn (array $a, array $b): int => strcmp((string) $a[$labelKey], (string) $b[$labelKey])
        );

        return array_merge($arr, $values);
    }

    /**
     * Returns a comma-separated string of enum values.
     */
    public static function commaSeparatedValues(): string
    {
        return implode(',', self::values());
    }

    /**
     * returns the enum value as a string
     */
    public function toString(): string
    {
        return (string) $this->value;
    }

    public function equals(int|string $value): bool
    {
        return $this->value === $value;
    }
}
