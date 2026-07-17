<?php

declare(strict_types=1);

use App\Services\MediaReplacement\MediaReplacementActionPayload;

test('it builds the existing replacement action payload with an optional subtitle case correlation', function (): void {
    $target = [
        'service' => 'sonarr',
        'service_connection_id' => 3,
        'scope' => 'anime',
        'display_name' => 'Frieren S01E01',
        'series_id' => 42,
        'episode_ids' => [101],
        'episode_file_ids' => [501],
        'original_history_id' => 999,
    ];
    $candidate = [
        'fingerprint' => 'candidate-fingerprint',
        'confidence' => 97,
        'matched_rules' => [['name' => 'Trusted English']],
    ];

    $payload = new MediaReplacementActionPayload()->build(
        target: $target,
        candidate: $candidate,
        effectiveLanguages: ['eng'],
        matchedRules: ['Trusted English'],
        selectionMode: 'automatic',
        reason: str_repeat('R', 1_100),
        subtitleCaseId: 42,
    );

    expect($payload)->toMatchArray([
        'title' => 'Replace Frieren S01E01',
        'service' => 'sonarr',
        'service_connection_id' => 3,
        'scope' => 'anime',
        'target' => $target,
        'candidate_fingerprint' => 'candidate-fingerprint',
        'candidate' => $candidate,
        'required_languages' => ['eng'],
        'confidence' => 97,
        'matched_rules' => ['Trusted English'],
        'selection_mode' => 'automatic',
        'original_history_id' => 999,
        'subtitle_case_id' => 42,
    ])
        ->and($payload['agent_rationale'])->toHaveLength(1_000)
        ->and($payload['detail'])->toHaveLength(1_100);
});

test('it omits subtitle case correlation for ordinary Media Advisor chat requests', function (): void {
    $payload = new MediaReplacementActionPayload()->build(
        target: ['service' => 'radarr', 'display_name' => 'Movie'],
        candidate: ['fingerprint' => 'candidate', 'confidence' => 90],
        effectiveLanguages: ['eng'],
        matchedRules: [],
        selectionMode: 'manual',
        reason: 'User selected this release.',
    );

    expect($payload)->not->toHaveKey('subtitle_case_id');
});
