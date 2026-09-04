<?php

declare(strict_types=1);

use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

test('index exposes one summarised row per attempt', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->monitoringSuspended()->create([
        'candidate' => ['title' => 'Trusted.Anime.S01E01.CR', 'release_group' => 'CR', 'quality' => 'WEBDL-1080p', 'confidence' => 98],
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/MediaReplacement/Attempts/Index')
            ->has('attempts.data', 1)
            ->where('attempts.data.0.id', $attempt->id)
            ->where('attempts.data.0.action_request_id', $attempt->action_request_id)
            ->where('attempts.data.0.action_request_status', 'pending')
            ->where('attempts.data.0.status', 'verified')
            ->where('attempts.data.0.scope', 'anime')
            ->where('attempts.data.0.service_name', 'Sonarr')
            ->where('attempts.data.0.service_type', 'sonarr')
            ->where('attempts.data.0.display_name', 'Trusted Anime S01E01')
            ->where('attempts.data.0.season_number', 1)
            ->where('attempts.data.0.episode_numbers', [1])
            ->where('attempts.data.0.candidate_title', 'Trusted.Anime.S01E01.CR')
            ->where('attempts.data.0.candidate_release_group', 'CR')
            ->where('attempts.data.0.candidate_quality', 'WEBDL-1080p')
            ->where('attempts.data.0.required_languages', ['eng'])
            ->where('attempts.data.0.verification.subtitles_checked', true)
            ->where('attempts.data.0.verification.found', ['eng'])
            ->where('attempts.data.0.verification.missing', [])
            ->where('attempts.data.0.monitoring_suspended', true)
            ->where('attempts.data.0.acknowledged_at', null)
            ->has('attempts.data.0.started_at')
            ->has('attempts.data.0.completed_at')
            ->has('attempts.meta.total')
            ->has('filterOptions.statuses', 6)
            ->has('filterOptions.scopes', 3)
            ->has('filterOptions.services', 1)
            ->where('statusCounts.verified', 1)
            ->where('statusCounts.attention_unacknowledged', 0)
        );
});

test('index filters by status, scope and service', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create();
    MediaReplacementAttempt::factory()->verified()->create();
    $failedMovie = MediaReplacementAttempt::factory()->failed()->radarr()->create(['service_connection_id' => $radarr->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['status' => 'failed']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.id', $failedMovie->id)->where('filters.status', 'failed'));

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['scope' => 'movie']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.scope', 'movie'));

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['service_id' => $radarr->id]))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.service_type', 'radarr')->where('filters.service_id', $radarr->id));

    // Unknown filter values are ignored rather than matching nothing.
    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['status' => 'bogus']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 2)->where('filters.status', null));
});

test('index searches the target display name and the candidate title', function (): void {
    MediaReplacementAttempt::factory()->verified()->create([
        'target' => ['service' => 'sonarr', 'display_name' => 'Cowboy Bebop S01E05', 'series_id' => 1, 'episode_ids' => [5], 'episode_file_ids' => [50]],
        'candidate' => ['title' => 'Cowboy.Bebop.S01E05.CR'],
    ]);
    MediaReplacementAttempt::factory()->verified()->create([
        'target' => ['service' => 'sonarr', 'display_name' => 'Trigun S01E01', 'series_id' => 2, 'episode_ids' => [6], 'episode_file_ids' => [60]],
        'candidate' => ['title' => 'Trigun.S01E01.Group_A'],
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['search' => 'bebop']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.display_name', 'Cowboy Bebop S01E05'));

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['search' => 'group_a']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 1)->where('attempts.data.0.display_name', 'Trigun S01E01'));

    // LIKE metacharacters are literal.
    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['search' => '%']))
        ->assertInertia(fn ($page) => $page->has('attempts.data', 0));
});

test('index can hide acknowledged attempts and counts them independently of filters', function (): void {
    MediaReplacementAttempt::factory()->needsAttention()->create();
    MediaReplacementAttempt::factory()->needsAttention()->acknowledged()->create();
    MediaReplacementAttempt::factory()->downloading()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.index', ['status' => 'needs_attention', 'unacknowledged' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('attempts.data', 1)
            ->where('attempts.data.0.acknowledged_at', null)
            ->where('filters.unacknowledged', true)
            ->where('statusCounts.needs_attention', 2)
            ->where('statusCounts.downloading', 1)
            ->where('statusCounts.attention_unacknowledged', 1)
        );
});

test('index is admin only', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.media-replacement.attempts.index'))
        ->assertForbidden();
});
