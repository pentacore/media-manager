<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleRuleStrength;
use Illuminate\Support\Str;

final class ReleaseRuleMatcher
{
    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $release
     */
    public function matches(array $rule, array $release): bool
    {
        if (($rule['enabled'] ?? null) !== true) {
            return false;
        }

        if (! is_string($rule['strength'] ?? null)
            || SubtitleRuleStrength::tryFrom($rule['strength']) === null) {
            return false;
        }

        if (! $this->hasLanguages($rule['languages'] ?? null)
            || ! is_array($rule['conditions'] ?? null)
            || $rule['conditions'] === []) {
            return false;
        }

        foreach ($rule['conditions'] as $condition) {
            if (! is_array($condition)
                || ! is_string($condition['field'] ?? null)
                || ! is_string($condition['value'] ?? null)) {
                return false;
            }

            $field = $this->normalizedMatchString($condition['field']);
            $value = trim($condition['value']);

            if ($field === null || $field === '' || $value === '' || ! $this->conditionMatches($field, $value, $release)) {
                return false;
            }
        }

        return true;
    }

    private function hasLanguages(mixed $languages): bool
    {
        if (! is_array($languages)) {
            return false;
        }

        return array_any(
            $languages,
            fn ($language): bool => is_string($language)
                && mb_check_encoding($language, 'UTF-8')
                && trim($language) !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function conditionMatches(string $field, string $value, array $release): bool
    {
        return match ($field) {
            'release_group' => $this->exact($release['releaseGroup'] ?? null, $value),
            'subgroup' => $this->exact($release['subGroup'] ?? null, $value),
            'title' => $this->title($release['title'] ?? null, $value),
            'custom_format' => $this->customFormat($release['customFormats'] ?? null, $value),
            default => false,
        };
    }

    private function exact(mixed $actual, string $expected): bool
    {
        if (! is_string($actual)) {
            return false;
        }

        $normalizedActual = $this->normalizedMatchString($actual);
        $normalizedExpected = $this->normalizedMatchString($expected);

        return $normalizedActual !== null
            && $normalizedExpected !== null
            && $normalizedActual === $normalizedExpected;
    }

    private function title(mixed $title, string $needle): bool
    {
        if (! is_string($title)
            || ! mb_check_encoding($title, 'UTF-8')
            || ! mb_check_encoding($needle, 'UTF-8')) {
            return false;
        }

        $tokens = preg_split('/[^\pL\pN]+/u', trim($needle), flags: PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens) || $tokens === []) {
            return false;
        }

        $quotedTokens = array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            $tokens,
        );
        $pattern = '/(?<![\pL\pN])'.implode('[^\pL\pN]+', $quotedTokens).'(?![\pL\pN])/iu';

        return preg_match($pattern, $title) === 1;
    }

    private function customFormat(mixed $customFormats, string $expected): bool
    {
        if (! is_array($customFormats)) {
            return false;
        }

        foreach ($customFormats as $customFormat) {
            $name = match (true) {
                is_array($customFormat) => $customFormat['name'] ?? null,
                is_object($customFormat) => get_object_vars($customFormat)['name'] ?? null,
                default => null,
            };

            if ($this->exact($name, $expected)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedMatchString(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return Str::of($value)->trim()->lower()->toString();
    }
}
