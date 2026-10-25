<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ServerMetricSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Override;

/**
 * A host's CPU, load and memory at one moment, stored per rollup resolution (60 s, 300 s, 3600 s).
 *
 * @property int $id
 * @property int $server_id
 * @property int $resolution
 * @property CarbonImmutable $recorded_at
 * @property float $cpu_percent
 * @property float $load1
 * @property int $memory_used_bytes
 * @property int $memory_total_bytes
 * @property int $swap_used_bytes
 * @property int $swap_total_bytes
 * @property-read Server $server
 *
 * @method static ServerMetricSampleFactory factory($count = null, $state = [])
 * @method static Builder<static>|ServerMetricSample newModelQuery()
 * @method static Builder<static>|ServerMetricSample newQuery()
 * @method static Builder<static>|ServerMetricSample query()
 */
#[Fillable([
    'server_id',
    'resolution',
    'recorded_at',
    'cpu_percent',
    'load1',
    'memory_used_bytes',
    'memory_total_bytes',
    'swap_used_bytes',
    'swap_total_bytes',
])]
#[WithoutTimestamps]
class ServerMetricSample extends Model
{
    /** @use HasFactory<ServerMetricSampleFactory> */
    use HasFactory;

    use MassPrunable;

    public const int MINUTE = 60;

    public const int FIVE_MINUTES = 300;

    public const int HOUR = 3600;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'cpu_percent' => 'float',
            'load1' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Retention window in days for a resolution (0 keeps it forever).
     */
    public static function retentionDays(int $resolution): int
    {
        return (int) config(match ($resolution) {
            self::MINUTE => 'mediamanager.retention.server_metrics_minute_days',
            self::FIVE_MINUTES => 'mediamanager.retention.server_metrics_five_minute_days',
            self::HOUR => 'mediamanager.retention.server_metrics_hour_days',
            default => throw new InvalidArgumentException(sprintf('Unknown metric resolution %d.', $resolution)),
        });
    }

    /**
     * Rows older than their own resolution's window. Shared by both sample models; a window of 0 prunes nothing.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereExpired(Builder $query): Builder
    {
        $windows = array_filter([
            self::MINUTE => self::retentionDays(self::MINUTE),
            self::FIVE_MINUTES => self::retentionDays(self::FIVE_MINUTES),
            self::HOUR => self::retentionDays(self::HOUR),
        ], static fn (int $days): bool => $days > 0);

        if ($windows === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($windows): void {
            foreach ($windows as $resolution => $days) {
                $builder->orWhere(fn (Builder $window): Builder => $window
                    ->where('resolution', $resolution)
                    ->where('recorded_at', '<', now()->subDays($days)));
            }
        });
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::whereExpired(static::query());
    }
}
