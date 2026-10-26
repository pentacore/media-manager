<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('an admin reads the batch card with each episode failure reason', function (): void {
    $batch = SubtitleAuditBatch::factory()->ready()->create(['series_id' => 42, 'series_title' => 'Severance']);
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    SubtitleAuditEpisode::factory()->failed()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 1,
        'failure_reason' => 'replacement_in_flight',
    ]);
    SubtitleAuditEpisode::factory()->queued()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 2,
        'action_request_id' => $pending->id,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.subtitle-audit-batches.show', $batch))
        ->assertOk()
        ->assertJsonPath('type', 'subtitle_audit_batch')
        ->assertJsonPath('id', $batch->id)
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('episodes.0.label', 'S01E01')
        ->assertJsonPath('episodes.0.failure_reason', 'replacement_in_flight')
        ->assertJsonPath('episodes.1.failure_reason', null)
        ->assertJsonPath('episodes.1.action_status', 'pending')
        ->assertJsonPath('pending_action_request_ids', [$pending->id]);
});

test('a member cannot read a batch card', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('ai.chat.subtitle-audit-batches.show', $batch))
        ->assertForbidden();
});

test('a guest is sent to the login page', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();

    $this->get(route('ai.chat.subtitle-audit-batches.show', $batch))
        ->assertRedirect(route('login'));
});

test('an unknown batch is not found', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.subtitle-audit-batches.show', ['subtitleAuditBatch' => 999_999]))
        ->assertNotFound();
});
