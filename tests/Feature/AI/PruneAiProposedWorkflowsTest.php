<?php

declare(strict_types=1);

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->admin()->create();
});

test('auto-declines Proposed workflows older than --stale-days', function (): void {
    $stale = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    $fresh = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'created_at' => CarbonImmutable::now()->subDays(2),
    ]);

    $this->artisan('ai:prune-proposed-workflows', ['--stale-days' => 7, '--days' => 30])
        ->assertSuccessful();

    expect($stale->fresh()->status)->toBe(AiProposedWorkflowStatus::Declined);
    expect($fresh->fresh()->status)->toBe(AiProposedWorkflowStatus::Proposed);
});

test('deletes terminal-status workflows older than --days but keeps recent ones', function (): void {
    $oldDeclined = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Declined,
        'updated_at' => CarbonImmutable::now()->subDays(40),
    ]);

    $oldExecuted = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Executed,
        'updated_at' => CarbonImmutable::now()->subDays(40),
    ]);

    $recentApproved = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Approved,
        'updated_at' => CarbonImmutable::now()->subDays(5),
    ]);

    $this->artisan('ai:prune-proposed-workflows', ['--stale-days' => 7, '--days' => 30])
        ->assertSuccessful();

    expect(AiProposedWorkflow::find($oldDeclined->id))->toBeNull();
    expect(AiProposedWorkflow::find($oldExecuted->id))->toBeNull();
    expect(AiProposedWorkflow::find($recentApproved->id))->not->toBeNull();
});

test('still-Proposed workflows are auto-declined first, then survive same-run prune', function (): void {
    // The auto-decline branch updates status to Declined, which touches
    // updated_at. So the same-run prune leaves them; the next day's prune
    // will collect them once `updated_at` ages past --days.
    $ancientProposed = AiProposedWorkflow::factory()->create([
        'user_id' => $this->user->id,
        'status' => AiProposedWorkflowStatus::Proposed,
        'created_at' => CarbonImmutable::now()->subDays(60),
        'updated_at' => CarbonImmutable::now()->subDays(60),
    ]);

    $this->artisan('ai:prune-proposed-workflows', ['--stale-days' => 7, '--days' => 30])
        ->assertSuccessful();

    $row = AiProposedWorkflow::find($ancientProposed->id);
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(AiProposedWorkflowStatus::Declined);
});
