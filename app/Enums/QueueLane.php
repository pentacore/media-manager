<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Named queues ("lanes") on the default queue connection. Case order is the
 * worker priority order: a general worker drains actions before webhooks
 * before everything else. The ai lane has its own worker (the production
 * queue-ai service) so a slow model call never delays an approved action or
 * an inbound webhook. Default must equal the connection's default queue name:
 * jobs without a #[Queue] attribute, broadcasts, Scout syncs and queued
 * notifications land there.
 */
enum QueueLane: string
{
    use EnumUtils;

    case Actions = 'actions';
    case Webhooks = 'webhooks';
    case Default = 'default';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Actions => 'Actions',
            self::Webhooks => 'Webhooks',
            self::Default => 'Default',
            self::Ai => 'AI',
        };
    }

    public function hasDedicatedWorker(): bool
    {
        return $this === self::Ai;
    }

    /**
     * Lanes the general `queue` worker drains, in priority order.
     *
     * @return list<self>
     */
    public static function generalLanes(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $queueLane): bool => ! $queueLane->hasDedicatedWorker(),
        ));
    }
}
