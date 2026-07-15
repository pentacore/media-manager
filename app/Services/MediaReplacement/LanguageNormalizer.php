<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use Illuminate\Support\Str;

final class LanguageNormalizer
{
    /** @var array<string, string> */
    private const array ALIASES = [
        'en' => 'eng',
        'eng' => 'eng',
        'english' => 'eng',
        'sv' => 'swe',
        'swe' => 'swe',
        'swedish' => 'swe',
        'ja' => 'jpn',
        'jpn' => 'jpn',
        'japanese' => 'jpn',
        'da' => 'dan',
        'dan' => 'dan',
        'danish' => 'dan',
        'no' => 'nor',
        'nor' => 'nor',
        'norwegian' => 'nor',
        'fi' => 'fin',
        'fin' => 'fin',
        'finnish' => 'fin',
    ];

    public function normalize(?string $language): ?string
    {
        $normalizedLanguage = Str::of((string) $language)
            ->trim()
            ->lower()
            ->toString();

        if ($normalizedLanguage === '') {
            return null;
        }

        return self::ALIASES[$normalizedLanguage] ?? $normalizedLanguage;
    }

    /**
     * @param  array<array-key, mixed>  $languages
     * @return list<string>
     */
    public function normalizeMany(array $languages): array
    {
        $normalizedLanguages = [];
        $seenLanguages = [];

        foreach ($languages as $language) {
            if (! is_string($language) && $language !== null) {
                continue;
            }

            $normalizedLanguage = $this->normalize($language);
            if ($normalizedLanguage === null) {
                continue;
            }

            $languageKey = 'language:'.$normalizedLanguage;
            if (isset($seenLanguages[$languageKey])) {
                continue;
            }

            $seenLanguages[$languageKey] = true;
            $normalizedLanguages[] = $normalizedLanguage;
        }

        return $normalizedLanguages;
    }
}
