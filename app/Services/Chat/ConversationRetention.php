<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for the laravel/ai conversation tables (no app model) and the
 * chat attachment files stored beside them.
 */
final readonly class ConversationRetention
{
    /**
     * Attachments are stored before the agent runs and linked to their
     * conversation when the turn finishes; an unlinked one younger than this
     * may still be mid-turn.
     */
    private const int UNASSIGNED_ATTACHMENT_GRACE_HOURS = 24;

    /**
     * Delete conversations idle for longer than $days with their messages and
     * attachments. Returns the number of conversations removed.
     */
    public function pruneIdleConversations(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $pruned = 0;

        DB::table('agent_conversations')
            ->where('updated_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(100, function (Collection $conversations) use (&$pruned): void {
                $conversationIds = $conversations->pluck('id')->all();

                // foreach, not ->each(): each() stops at the first false, and a
                // failed file delete must not skip the remaining attachments.
                foreach (ChatAttachment::query()->whereIn('conversation_id', $conversationIds)->get() as $attachment) {
                    $this->deleteAttachment($attachment);
                }

                $pruned += DB::transaction(function () use ($conversationIds): int {
                    DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete();

                    return DB::table('agent_conversations')->whereIn('id', $conversationIds)->delete();
                });
            });

        return $pruned;
    }

    /**
     * Delete attachments no conversation will ever show: those whose
     * conversation is gone, and never-linked ones past the grace period that
     * no stored message references. Returns the number removed.
     */
    public function sweepOrphanedAttachments(): int
    {
        $swept = 0;

        ChatAttachment::query()
            ->where(fn (Builder $builder): Builder => $builder
                ->whereNotNull('conversation_id')
                ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query
                    ->selectRaw('1')
                    ->from('agent_conversations')
                    ->whereColumn('agent_conversations.id', 'chat_attachments.conversation_id')))
            ->orWhere(fn (Builder $builder): Builder => $builder
                ->whereNull('conversation_id')
                ->where('created_at', '<', now()->subHours(self::UNASSIGNED_ATTACHMENT_GRACE_HOURS)))
            ->chunkById(100, function (EloquentCollection $attachments) use (&$swept): void {
                foreach ($attachments as $attachment) {
                    if ($attachment->conversation_id === null && $this->isReferencedByAStoredMessage($attachment)) {
                        continue;
                    }

                    if ($this->deleteAttachment($attachment)) {
                        $swept++;
                    }
                }
            });

        return $swept;
    }

    /**
     * A stream that fails after the SDK stored the user message never links
     * the attachment, but the message still shows it. The SDK's JSON escapes
     * slashes, so match the unique file name rather than the path.
     */
    private function isReferencedByAStoredMessage(ChatAttachment $chatAttachment): bool
    {
        return DB::table('agent_conversation_messages')
            ->where('attachments', 'like', sprintf('%%%s%%', basename($chatAttachment->path)))
            ->exists();
    }

    /**
     * Only a real file deletion (or an already-missing file) removes the row;
     * otherwise the row stays so a later sweep retries instead of orphaning
     * the file on disk.
     */
    private function deleteAttachment(ChatAttachment $chatAttachment): bool
    {
        $disk = Storage::disk($chatAttachment->disk);

        if ($disk->exists($chatAttachment->path) && ! $disk->delete($chatAttachment->path)) {
            Log::warning('Chat attachment file could not be deleted; keeping its row for the next sweep.', [
                'attachment_id' => $chatAttachment->id,
            ]);

            return false;
        }

        $chatAttachment->delete();

        return true;
    }
}
