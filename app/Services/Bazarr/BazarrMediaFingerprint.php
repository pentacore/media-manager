<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use InvalidArgumentException;
use JsonException;

final class BazarrMediaFingerprint
{
    /**
     * @param  array<string, mixed>  $media
     *
     * @throws JsonException
     */
    public function make(string $mediaType, array $media): string
    {
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Bazarr media fingerprint type is invalid.');

        $identity = $mediaType === 'episode'
            ? [
                'media_type' => 'episode',
                'media_id' => $this->positiveInteger($media['sonarrEpisodeId'] ?? null),
                'series_id' => $this->positiveInteger($media['sonarrSeriesId'] ?? null),
                'scene_name' => $this->text($media['sceneName'] ?? null),
            ]
            : [
                'media_type' => 'movie',
                'media_id' => $this->positiveInteger($media['radarrId'] ?? null),
                'scene_name' => $this->text($media['sceneName'] ?? null),
            ];

        $requiredFields = $mediaType === 'episode'
            ? ['media_id', 'series_id']
            : ['media_id'];
        throw_if(
            array_any($requiredFields, static fn (string $field): bool => $identity[$field] === null),
            InvalidArgumentException::class,
            'Bazarr media fingerprint fields are incomplete.',
        );

        return hash_hmac(
            'sha256',
            json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $this->key(),
        );
    }

    /**
     * @param  array<string, mixed>  $media
     *
     * @throws JsonException
     */
    public function verify(string $mediaType, array $media, string $fingerprint): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && hash_equals($this->make($mediaType, $media), $fingerprint);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function key(): string
    {
        $key = config('app.key');
        throw_unless(is_string($key) && $key !== '', InvalidArgumentException::class, 'Bazarr fingerprints require an application key.');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            throw_if($decoded === false || $decoded === '', InvalidArgumentException::class, 'Bazarr fingerprints require a valid application key.');

            return $decoded;
        }

        return $key;
    }
}
