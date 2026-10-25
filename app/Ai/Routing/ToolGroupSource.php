<?php

declare(strict_types=1);

namespace App\Ai\Routing;

/**
 * Why a routed tool group is loaded for a chat turn.
 */
enum ToolGroupSource: string
{
    case Pinned = 'pinned';
    case Assistant = 'assistant';
    case CarriedOver = 'carried_over';
    case Picked = 'picked';

    public function label(): string
    {
        return match ($this) {
            self::Pinned => 'Pinned',
            self::Assistant => 'Assistant',
            self::CarriedOver => 'Carried over',
            self::Picked => 'Picked',
        };
    }
}
