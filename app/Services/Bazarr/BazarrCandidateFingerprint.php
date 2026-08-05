<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use InvalidArgumentException;
use JsonException;

final class BazarrCandidateFingerprint
{
    private const array FIELDS = [
        'media_type',
        'media_id',
        'provider',
        'subtitle',
        'language',
        'forced',
        'hearing_impaired',
        'score',
        'release_info',
    ];

    /**
     * @param  array<string, mixed>  $candidate
     *
     * @throws JsonException
     */
    public function make(array $candidate): string
    {
        return hash_hmac(
            'sha256',
            json_encode(
                $this->canonicalize(array_intersect_key($candidate, array_flip(self::FIELDS))),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            $this->key(),
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     *
     * @throws JsonException
     */
    public function verify(array $candidate, string $fingerprint): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && hash_equals($this->make($candidate), $fingerprint);
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

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
