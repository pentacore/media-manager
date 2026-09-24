<?php

declare(strict_types=1);

use App\Services\Bazarr\SubtitleOperationDescriber;

test('download_best names the media and the requested subtitle', function (): void {
    $description = resolve(SubtitleOperationDescriber::class)->describe(
        'download_best',
        ['title' => 'Severance S01E01'],
        ['language' => 'sv', 'forced' => false, 'hearing_impaired' => true],
    );

    expect($description->title)->toBe('Download the best subtitle for Severance S01E01')
        ->and($description->description)->toBe('Bazarr will search its providers and download the best-scoring subtitle.')
        ->and($description->details)->toBe([
            ['label' => 'Media', 'value' => 'Severance S01E01'],
            ['label' => 'Language', 'value' => 'sv'],
            ['label' => 'Forced', 'value' => 'No'],
            ['label' => 'Hearing impaired', 'value' => 'Yes'],
        ])
        ->and($description->verified)->toBeTrue();
});

test('track operations name the selected subtitle track', function (): void {
    $description = resolve(SubtitleOperationDescriber::class)->describe(
        'delete_subtitle',
        ['title' => 'Dune', 'subtitle_tracks' => [['fingerprint' => 'fp-1', 'display_name' => 'English (forced)']]],
        ['subtitle_fingerprint' => 'fp-1'],
    );

    expect($description->title)->toBe('Delete a subtitle for Dune')
        ->and($description->details)->toContain(['label' => 'Subtitle', 'value' => 'English (forced)']);
});

test('an upload is described with its language', function (): void {
    $description = resolve(SubtitleOperationDescriber::class)->describe(
        'upload_subtitle',
        ['title' => 'Dune'],
        ['language' => 'en', 'forced' => false, 'hearing_impaired' => false],
    );

    expect($description->title)->toBe('Upload a subtitle for Dune')
        ->and($description->description)->toBe('Bazarr will add the uploaded subtitle file to this media.');
});
