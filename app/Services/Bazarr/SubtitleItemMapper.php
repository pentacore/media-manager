<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\MediaReplacementScope;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\LanguageNormalizer;
use App\Services\MediaReplacement\SonarrMediaScopeResolver;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;

/**
 * Projects raw Bazarr episode, movie and history rows into the sanitized
 * subtitle inventory items every reader returns: MediaManager's required
 * languages, normalized tracks with opaque fingerprints, and bounded display
 * text that never carries an upstream path.
 */
final readonly class SubtitleItemMapper
{
    public function __construct(
        private MediaReplacementSettings $mediaReplacementSettings,
        private LanguageNormalizer $languageNormalizer,
        private SonarrMediaScopeResolver $sonarrMediaScopeResolver,
        private BazarrMediaFingerprint $bazarrMediaFingerprint,
        private BazarrSubtitleFingerprint $bazarrSubtitleFingerprint,
    ) {}

    /**
     * @param  array<string, mixed>  $episode
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>|null
     */
    public function episodeItem(array $episode, array $series, ServiceConnection $serviceConnection): ?array
    {
        $mediaId = $this->positiveInteger($episode['sonarrEpisodeId'] ?? null);
        $seriesId = $this->positiveInteger($episode['sonarrSeriesId'] ?? null);
        $scope = $this->sonarrMediaScopeResolver->resolve($serviceConnection, $series);

        if ($mediaId === null || $seriesId === null || ! $scope instanceof MediaReplacementScope) {
            return null;
        }

        $tracks = $this->subtitleTracks($episode['subtitles'] ?? null, 'episode', $mediaId);
        $requiredLanguages = $this->mediaReplacementSettings->effectiveLanguages($scope);

        return [
            'media_type' => 'episode',
            'media_id' => $mediaId,
            'series_id' => $seriesId,
            'target_fingerprint' => $this->bazarrMediaFingerprint->make('episode', $episode),
            'scope' => $scope->value,
            'title' => $this->episodeTitle($series, $episode),
            'subtitle_tracks' => $tracks,
            'required_languages' => $requiredLanguages,
            'missing_languages' => $this->missingLanguages($requiredLanguages, $tracks),
            'monitored' => ($episode['monitored'] ?? true) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>|null
     */
    public function movieItem(array $movie): ?array
    {
        $mediaId = $this->positiveInteger($movie['radarrId'] ?? null);

        if ($mediaId === null) {
            return null;
        }

        $tracks = $this->subtitleTracks($movie['subtitles'] ?? null, 'movie', $mediaId);
        $requiredLanguages = $this->mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Movie);

        return [
            'media_type' => 'movie',
            'media_id' => $mediaId,
            'target_fingerprint' => $this->bazarrMediaFingerprint->make('movie', $movie),
            'scope' => MediaReplacementScope::Movie->value,
            'title' => $this->safeText($movie['title'] ?? null, 'Movie '.$mediaId),
            'subtitle_tracks' => $tracks,
            'required_languages' => $requiredLanguages,
            'missing_languages' => $this->missingLanguages($requiredLanguages, $tracks),
            'monitored' => ($movie['monitored'] ?? true) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>|null
     */
    public function historyItem(array $history, string $mediaType): ?array
    {
        $mediaId = $this->positiveInteger(
            $mediaType === 'episode'
                ? ($history['sonarrEpisodeId'] ?? null)
                : ($history['radarrId'] ?? null),
        );

        if ($mediaId === null) {
            return null;
        }

        $languagePayload = $history['language'] ?? null;
        $language = $this->languageNormalizer->normalize(
            is_array($languagePayload)
                ? $this->firstString($languagePayload, ['code3', 'code2', 'name'])
                : (is_string($languagePayload) ? $languagePayload : null),
        );

        if ($language === null) {
            return null;
        }

        $title = $mediaType === 'episode'
            ? $this->safeText($history['seriesTitle'] ?? null, 'Series')
                .' — '.$this->safeText($history['episodeTitle'] ?? null, 'Episode')
            : $this->safeText($history['title'] ?? null, 'Movie '.$mediaId);

        return [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'title' => $title,
            'language' => $language,
            'provider' => $this->safeProvider($history['provider'] ?? null),
            'action' => is_int($history['action'] ?? null) ? $history['action'] : null,
            'score' => $this->safeText($history['score'] ?? null, ''),
            'occurred_at' => $this->safeText($history['parsed_timestamp'] ?? $history['timestamp'] ?? null, ''),
        ];
    }

    /**
     * @return list<string>
     */
    public function currentSubtitleLanguages(mixed $tracks): array
    {
        if (! is_array($tracks)) {
            return [];
        }

        return $this->languageNormalizer->normalizeMany(array_map(
            static fn (mixed $track): mixed => is_array($track) ? ($track['language'] ?? null) : null,
            $tracks,
        ));
    }

    public function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<array{
     *     fingerprint: string,
     *     display_name: string,
     *     language: string,
     *     kind: 'embedded'|'external',
     *     forced: bool,
     *     hearing_impaired: bool
     * }>
     */
    private function subtitleTracks(mixed $tracks, string $mediaType, int $mediaId): array
    {
        if (! is_array($tracks)) {
            return [];
        }

        $normalizedTracks = [];

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $language = $this->languageNormalizer->normalize(
                $this->firstString($track, ['code3', 'code2', 'language', 'name']),
            );

            if ($language === null) {
                continue;
            }

            $path = is_string($track['path'] ?? null) ? $track['path'] : null;
            $kind = $path === null || $this->positiveInteger($track['embedded_track_id'] ?? null) !== null
                ? 'embedded'
                : 'external';
            $displayName = $kind === 'external'
                ? $this->safeBasename($path, Str::upper($language).' subtitle')
                : Str::upper($language).' embedded track';

            $normalizedTracks[] = [
                'fingerprint' => $this->trackFingerprint($mediaType, $mediaId, $track, $displayName),
                'display_name' => $displayName,
                'language' => $language,
                'kind' => $kind,
                'forced' => ($track['forced'] ?? false) === true,
                'hearing_impaired' => ($track['hi'] ?? $track['hearing_impaired'] ?? false) === true,
            ];
        }

        return $normalizedTracks;
    }

    /**
     * @param  list<string>  $requiredLanguages
     * @param  list<array{language: string}>  $tracks
     * @return list<string>
     */
    private function missingLanguages(array $requiredLanguages, array $tracks): array
    {
        $installedLanguages = array_column($tracks, 'language');

        return array_values(array_diff($requiredLanguages, $installedLanguages));
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $episode
     */
    private function episodeTitle(array $series, array $episode): string
    {
        $seriesTitle = $this->safeText($series['title'] ?? null, 'Series');
        $episodeTitle = $this->safeText($episode['title'] ?? $episode['episodeTitle'] ?? null, 'Episode');

        return $seriesTitle.' — '.$episodeTitle;
    }

    private function safeText(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $fallback;
        }

        $value = Str::of($value)->squish()->limit(250)->toString();

        return $value === '' ? $fallback : $value;
    }

    private function safeBasename(?string $path, string $fallback): string
    {
        if ($path === null || ! mb_check_encoding($path, 'UTF-8')) {
            return $fallback;
        }

        $basename = Str::afterLast(str_replace('\\', '/', $path), '/');

        return $this->safeText($basename, $fallback);
    }

    private function safeProvider(mixed $provider): ?string
    {
        if (! is_string($provider)) {
            return null;
        }

        $provider = $this->safeText($provider, '');

        if ($provider === '' || Str::startsWith($provider, ['http://', 'https://'])) {
            return null;
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($values, $key);

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $track
     *
     * @throws JsonException
     */
    private function trackFingerprint(string $mediaType, int $mediaId, array $track, string $displayName): string
    {
        return $this->bazarrSubtitleFingerprint->make([
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'path' => is_string($track['path'] ?? null) ? $track['path'] : null,
            'language' => $this->firstString($track, ['code3', 'code2', 'language', 'name']),
            'forced' => ($track['forced'] ?? false) === true,
            'hearing_impaired' => ($track['hi'] ?? $track['hearing_impaired'] ?? false) === true,
            'display_name' => $displayName,
        ]);
    }
}
