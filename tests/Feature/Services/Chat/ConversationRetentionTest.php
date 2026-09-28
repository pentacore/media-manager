<?php

declare(strict_types=1);

use App\Models\ChatAttachment;
use App\Services\Chat\ConversationRetention;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
});

test('sweeping orphaned attachments across more than one chunk visits each row once and terminates', function (): void {
    // Every attachment below points at a conversation_id no agent_conversations
    // row has, so all 150 match the "conversation gone" branch of the sweep's
    // where/orWhere. That is more than one chunkById page (100 per page).
    $orphans = ChatAttachment::factory()
        ->count(150)
        ->create(['conversation_id' => fn (): string => (string) Str::uuid()]);

    // Make every delete fail, as a real filesystem error would, so rows are
    // never removed from the "still pending" set the buggy query re-visits.
    // The buggy ungrouped OR makes chunkById's `id > lastId` bind only to the
    // unassigned-attachment branch, so it re-fetches the same first 100
    // conversation-gone rows forever. Guard with a hard cap so that bug fails
    // this test with a clear exception instead of hanging the suite.
    $deleteAttempts = 0;
    $legacyMock = Mockery::mock(Storage::disk('local'))->makePartial();
    $legacyMock->shouldReceive('delete')->andReturnUsing(function () use (&$deleteAttempts): bool {
        $deleteAttempts++;

        throw_if($deleteAttempts > 300, RuntimeException::class, 'sweepOrphanedAttachments revisited rows instead of terminating');

        return false;
    });
    Storage::set('local', $legacyMock);

    $swept = resolve(ConversationRetention::class)->sweepOrphanedAttachments();

    expect($deleteAttempts)->toBe(150)
        ->and($swept)->toBe(0)
        ->and(ChatAttachment::query()->whereIn('id', $orphans->pluck('id'))->count())->toBe(150);
});
