<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * The text-generation workloads that carry their own model and reasoning
 * selection (Admin → AI Models). Classification, reranking and embeddings
 * are not tasks: they are configured on AI Settings.
 */
enum AiTask: string
{
    use EnumUtils;

    case Chat = 'chat';
    case Title = 'title';
    case Decision = 'decision';
    case FileInspector = 'file_inspector';
    case StuckDownloadInvestigator = 'stuck_download_investigator';
    case PriceUpdater = 'price_updater';
    case Failover = 'failover';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Title => 'Conversation titles',
            self::Decision => 'Decision agent',
            self::FileInspector => 'File inspector',
            self::StuckDownloadInvestigator => 'Stuck download investigator',
            self::PriceUpdater => 'Price updater',
            self::Failover => 'Failover',
        };
    }

    /**
     * Whether the task takes per-webhook-event override rows.
     */
    public function acceptsEventScopes(): bool
    {
        return $this === self::Decision;
    }

    /**
     * Whether a null model on the task's default row follows the chat model.
     */
    public function inheritsChatModel(): bool
    {
        return in_array($this, [self::Decision, self::FileInspector, self::StuckDownloadInvestigator, self::PriceUpdater], true);
    }

    /**
     * Whether the task has a reasoning setting of its own. Failover runs at
     * the failing task's level.
     */
    public function hasReasoning(): bool
    {
        return $this !== self::Failover;
    }
}
