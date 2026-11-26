<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Middleware\ClientDisconnectedException;
use App\Models\AiUsageRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function legacyStopsMigration(): object
{
    return require database_path('migrations/2026_11_26_100000_mark_legacy_stopped_turns_as_stopped.php');
}

/**
 * @param  array<string, mixed>  $meta
 */
function seedLegacyAssistantTurn(string $status, array $meta): string
{
    $user = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'title' => 'Legacy', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId, 'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => MediaAgent::class, 'role' => 'assistant', 'content' => 'Partial', 'attachments' => '[]',
        'steps' => '[]', 'usage' => '{}', 'meta' => json_encode($meta), 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $messageId;
}

test('the stop message the migration matches is still the one a Stop throws', function (): void {
    expect(ClientDisconnectedException::MESSAGE)->toBe('The chat client disconnected; the turn was stopped before its next step.');
});

test('a turn stored as failed by a Stop becomes a completed stopped turn without the error', function (): void {
    $stopped = seedLegacyAssistantTurn('failed', ['provider' => 'openai', 'error' => ClientDisconnectedException::MESSAGE]);
    $failed = seedLegacyAssistantTurn('failed', ['provider' => 'openai', 'error' => 'Provider returned 500.']);

    legacyStopsMigration()->up();

    $stoppedRow = DB::table('agent_conversation_messages')->where('id', $stopped)->sole();
    $failedRow = DB::table('agent_conversation_messages')->where('id', $failed)->sole();

    expect($stoppedRow->status)->toBe('completed')
        ->and(json_decode($stoppedRow->meta, true))->toBe(['provider' => 'openai', 'stopped' => true])
        ->and($failedRow->status)->toBe('failed')
        ->and(json_decode($failedRow->meta, true))->toBe(['provider' => 'openai', 'error' => 'Provider returned 500.']);
});

test('a usage row stored as failed by a Stop becomes stopped without an error', function (): void {
    $stopped = AiUsageRecord::factory()->create(['status' => 'failed', 'error_message' => ClientDisconnectedException::MESSAGE, 'error_code' => 'runtime_exception']);
    $failed = AiUsageRecord::factory()->failed()->create(['error_code' => 'server_error']);
    $prefixed = AiUsageRecord::factory()->create(['status' => 'failed', 'error_message' => 'Agent failed: '.ClientDisconnectedException::MESSAGE]);

    legacyStopsMigration()->up();

    expect($stopped->refresh())
        ->status->toBe('stopped')
        ->error_message->toBeNull()
        ->error_code->toBeNull()
        ->and($failed->refresh())
        ->status->toBe('failed')
        ->error_message->toBe('Provider returned 500.')
        ->error_code->toBe('server_error')
        ->and($prefixed->refresh()->status)->toBe('failed');
});
