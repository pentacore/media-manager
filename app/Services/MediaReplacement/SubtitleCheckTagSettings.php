<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Models\ServiceConnection;

/**
 * Per-connection arr tag labels that opt a series or movie into the automatic
 * subtitle check on import.
 *
 * Stored on the connection itself rather than in MediaReplacementSettings:
 * per-connection state keyed by service_connection_id inside the global
 * settings blob is the shape sonarr_root_folders is migrating away from, and
 * the AI settings page rewrites that blob wholesale.
 */
class SubtitleCheckTagSettings
{
    /**
     * @return list<string>
     */
    public function forConnection(ServiceConnection $serviceConnection): array
    {
        $settings = is_array($serviceConnection->settings) ? $serviceConnection->settings : [];

        return $this->normalize($settings['subtitle_check_tags'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @param  array<array-key, mixed>  $tags
     * @return array<array-key, mixed>
     */
    public function mergeInto(array $settings, array $tags): array
    {
        $settings['subtitle_check_tags'] = $this->normalize($tags);

        return $settings;
    }

    /**
     * Labels are compared case-insensitively against the arr's own tag labels,
     * so they are stored folded — that keeps the comparison a plain equality
     * check at every call site.
     *
     * @return list<string>
     */
    private function normalize(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $label = mb_strtolower(trim($tag));

            if ($label === '') {
                continue;
            }

            $normalized[$label] = $label;
        }

        return array_values($normalized);
    }
}
