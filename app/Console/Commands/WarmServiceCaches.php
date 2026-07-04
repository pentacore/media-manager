<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
use App\Support\Cache\Warmable;
use App\Support\Presence\PresenceTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pre-fetch near-expiry external API caches (Sonarr/Radarr/Seerr) so that
 * page loads stay snappy. Bails out immediately when no users are
 * actively interacting with the app — there is no point heating up
 * upstream APIs when nobody will benefit.
 */
#[Description('Refresh near-expiry external API caches for active services. No-op when no users are present.')]
#[Signature('services:warm-caches')]
class WarmServiceCaches extends Command
{
    /**
     * @var list<ServiceType>
     */
    private const array WARMABLE_TYPES = [
        ServiceType::Sonarr,
        ServiceType::Radarr,
        ServiceType::Seerr,
    ];

    public function handle(
        PresenceTracker $presenceTracker,
        ServiceClientFactory $serviceClientFactory,
    ): int {
        if (! $presenceTracker->hasActiveUsers()) {
            $this->info('No active users — skipping cache warm.');

            return self::SUCCESS;
        }

        $connections = ServiceConnection::query()
            ->where('is_active', true)
            ->whereIn('type', array_map(fn (ServiceType $serviceType): string => $serviceType->value, self::WARMABLE_TYPES))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active warmable service connections.');

            return self::SUCCESS;
        }

        $warmed = 0;

        foreach ($connections as $connection) {
            try {
                $client = $serviceClientFactory->make($connection);

                if (! $client instanceof Warmable) {
                    continue;
                }

                $client->warm();
                $warmed++;
            } catch (Throwable $throwable) {
                Log::warning('cache warm failed', [
                    'service' => $connection->type->value,
                    'connection_id' => $connection->id,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('Warmed %d connection(s).', $warmed));

        return self::SUCCESS;
    }
}
