<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Named queues ("lanes") on the default queue connection. Case order is the
 * worker priority order: a general worker drains actions before webhooks
 * before everything else. The ai and maintenance lanes have their own worker
 * (the production queue-ai service, ai first) so a slow model call or a long
 * housekeeping job never delays an approved action or an inbound webhook.
 * Default must equal the connection's default queue name: jobs without a
 * #[Queue] attribute, broadcasts, Scout syncs and queued notifications land
 * there.
 */
enum QueueLane: string
{
    use EnumUtils;

    case Actions = 'actions';
    case Webhooks = 'webhooks';
    case Default = 'default';
    case Ai = 'ai';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Actions => 'Actions',
            self::Webhooks => 'Webhooks',
            self::Default => 'Default',
            self::Ai => 'AI',
            self::Maintenance => 'Maintenance',
        };
    }

    public function hasDedicatedWorker(): bool
    {
        return $this === self::Ai || $this === self::Maintenance;
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
