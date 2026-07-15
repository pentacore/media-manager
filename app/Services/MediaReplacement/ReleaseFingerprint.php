<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

final class ReleaseFingerprint
{
    /**
     * @param  array<string, mixed>  $release
     */
    public function make(string $service, array $release): string
    {
        $identity = [
            'service' => $this->string($service),
            'indexer_id' => $this->integer($release['indexerId'] ?? null) ?? 0,
            'guid' => $this->string($release['guid'] ?? null),
            'title' => $this->string($release['title'] ?? null),
            'mapped_ids' => $this->mappedIds($service, $release),
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $release
     * @return list<int>
     */
    private function mappedIds(string $service, array $release): array
    {
        $rawIds = match (strtolower(trim($service))) {
            'radarr' => [$release['movieId'] ?? null],
            default => is_array($release['episodeIds'] ?? null)
                ? $release['episodeIds']
                : [$release['movieId'] ?? null],
        };
        $mappedIds = [];

        foreach ($rawIds as $rawId) {
            $mappedId = $this->integer($rawId);

            if ($mappedId !== null) {
                $mappedIds[$mappedId] = $mappedId;
            }
        }

        sort($mappedIds, SORT_NUMERIC);

        return array_values($mappedIds);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/D', trim($value)) !== 1) {
            return null;
        }

        $integer = filter_var(trim($value), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($integer) ? $integer : null;
    }

    private function string(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = (string) $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }
}
