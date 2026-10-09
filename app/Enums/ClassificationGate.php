<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * A Jev classification decision point whose outcomes are tracked.
 */
enum ClassificationGate: string
{
    use EnumUtils;

    case DecisionGate = 'decision_gate';
    case ActionKind = 'action_kind';
    case StuckImport = 'stuck_import';
    case SubtitleTriage = 'subtitle_triage';
    case ChatRouting = 'chat_routing';

    public function label(): string
    {
        return match ($this) {
            self::DecisionGate => 'Webhook decision gate',
            self::ActionKind => 'Webhook tool scoping',
            self::StuckImport => 'Stuck-import fast path',
            self::SubtitleTriage => 'Subtitle escalation triage',
            self::ChatRouting => 'Chat tool routing',
        };
    }
}
