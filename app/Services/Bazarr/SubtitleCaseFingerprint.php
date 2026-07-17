<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Services\MediaReplacement\LanguageNormalizer;
use InvalidArgumentException;
use JsonException;

final readonly class SubtitleCaseFingerprint
{
    public function __construct(private LanguageNormalizer $languageNormalizer) {}

    /**
     * @param  array<string, mixed>  $identity
     *
     * @throws JsonException
     */
    public function file(array $identity): string
    {
        $service = mb_strtolower(trim((string) ($identity['service'] ?? '')));
        throw_unless(in_array($service, ['sonarr', 'radarr'], true), InvalidArgumentException::class, 'File identity service is invalid.');
        $connectionId = $this->positiveInteger($identity['service_connection_id'] ?? null);
        throw_if($connectionId === null, InvalidArgumentException::class, 'File identity connection is invalid.');

        $material = [
            'service' => $service,
            'service_connection_id' => $connectionId,
            'file_ids' => $this->sortedIds($identity['file_ids'] ?? []),
            'media_ids' => $this->sortedIds($identity['media_ids'] ?? []),
            'size' => $this->nonNegativeInteger($identity['size'] ?? null),
            'date_added' => $this->text($identity['date_added'] ?? null),
            'scene_name' => $this->text($identity['scene_name'] ?? null),
        ];

        throw_if($material['file_ids'] === [] || $material['media_ids'] === [], InvalidArgumentException::class, 'File identity requires file and media IDs.');

        return $this->hash($material);
    }

    /**
     * @param  array<array-key, mixed>  $languages
     *
     * @throws JsonException
     */
    public function requirements(string $scope, array $languages): string
    {
        $scope = mb_strtolower(trim($scope));
        throw_unless(in_array($scope, ['anime', 'tv', 'movie'], true), InvalidArgumentException::class, 'Requirement scope is invalid.');
        $normalized = $this->languageNormalizer->normalizeMany($languages);
        sort($normalized);

        return $this->hash(['scope' => $scope, 'languages' => $normalized]);
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value);

        return array_map($this->canonical(...), $value);
    }

    /**
     * @return list<int>
     */
    private function sortedIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(
            array_map($this->positiveInteger(...), $ids),
            static fn (?int $id): bool => $id !== null,
        )));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
