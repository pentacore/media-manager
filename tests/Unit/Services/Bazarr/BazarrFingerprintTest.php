<?php

declare(strict_types=1);

use App\Services\Bazarr\BazarrCandidateFingerprint;
use App\Services\Bazarr\BazarrSubtitleFingerprint;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
});

test('candidate fingerprints are stable keyed and insensitive to associative key order', function (): void {
    $fingerprints = new BazarrCandidateFingerprint;
    $candidate = [
        'media_type' => 'episode',
        'media_id' => 42,
        'provider' => 'OpenSubtitles',
        'subtitle' => 'private-provider-id',
        'language' => 'sv',
        'forced' => false,
        'hearing_impaired' => true,
        'score' => 97,
        'release_info' => ['Release.One', 'Release.Two'],
    ];
    $reordered = array_reverse($candidate, preserve_keys: true);

    expect($fingerprints->make($candidate))
        ->toBe($fingerprints->make($reordered))
        ->toMatch('/^[a-f0-9]{64}$/')
        ->not->toContain('private-provider-id')
        ->and($fingerprints->verify($candidate, $fingerprints->make($candidate)))
        ->toBeTrue();
});

test('candidate fingerprints reject changed identity fields and another app key', function (): void {
    $fingerprints = new BazarrCandidateFingerprint;
    $candidate = [
        'media_type' => 'movie',
        'media_id' => 84,
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-id',
        'language' => 'en',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 88,
        'release_info' => ['Movie.Release'],
    ];
    $signature = $fingerprints->make($candidate);

    expect($fingerprints->verify([...$candidate, 'score' => 89], $signature))->toBeFalse();

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));

    expect($fingerprints->verify($candidate, $signature))->toBeFalse();
});

test('subtitle fingerprints bind the raw path without exposing it', function (): void {
    $fingerprints = new BazarrSubtitleFingerprint;
    $subtitle = [
        'media_type' => 'episode',
        'media_id' => 42,
        'path' => '/private/library/episode.sv.srt',
        'language' => 'sv',
        'forced' => false,
        'hearing_impaired' => true,
        'display_name' => 'episode.sv.srt',
    ];
    $signature = $fingerprints->make($subtitle);

    expect($signature)
        ->toMatch('/^[a-f0-9]{64}$/')
        ->not->toContain('/private/library')
        ->and($fingerprints->verify($subtitle, $signature))->toBeTrue()
        ->and($fingerprints->verify(
            [...$subtitle, 'path' => '/private/library/replaced.sv.srt'],
            $signature,
        ))
        ->toBeFalse();
});
