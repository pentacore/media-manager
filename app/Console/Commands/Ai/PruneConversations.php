<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Chat\ConversationRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete AI chat conversations idle past the retention window and sweep orphaned chat attachments.')]
#[Signature('ai:prune-conversations')]
class PruneConversations extends Command
{
    public function handle(ConversationRetention $conversationRetention): int
    {
        $conversations = $conversationRetention->pruneIdleConversations(
            (int) config('mediamanager.retention.agent_conversations_days'),
        );
        $attachments = $conversationRetention->sweepOrphanedAttachments();

        $this->info(sprintf('Pruned %d idle conversation(s); swept %d orphaned attachment(s).', $conversations, $attachments));

        return self::SUCCESS;
    }
}
