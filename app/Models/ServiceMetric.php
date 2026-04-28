<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $service_connection_id
 * @property HealthStatus $status
 * @property int|null $latency_ms
 * @property string|null $message
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection $serviceConnection
 *
 * @method static ServiceMetricFactory factory($count = null, $state = [])
 */
#[Fillable(['service_connection_id', 'status', 'latency_ms', 'message', 'recorded_at'])]
class ServiceMetric extends Model
{
    /** @use HasFactory<ServiceMetricFactory> */
    use HasFactory;

    #[Override]
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'latency_ms' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }
}
