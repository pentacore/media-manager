<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubtitleAuditBatchStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubtitleAuditBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $service_connection_id
 * @property int $series_id
 * @property string $series_title
 * @property int|null $season_number
 * @property SubtitleAuditBatchStatus $status
 * @property CarbonImmutable|null $search_deadline_at
 * @property CarbonImmutable|null $handed_off_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read ServiceConnection $serviceConnection
 * @property-read Collection<int, SubtitleAuditEpisode> $episodes
 * @property-read int|null $episodes_count
 *
 * @method static SubtitleAuditBatchFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'service_connection_id',
    'series_id',
    'series_title',
    'season_number',
    'status',
    'search_deadline_at',
    'handed_off_at',
    'completed_at',
])]
class SubtitleAuditBatch extends Model
{
    /** @use HasFactory<SubtitleAuditBatchFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    #[Override]
    protected $attributes = [
        'status' => 'searching',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return HasMany<SubtitleAuditEpisode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(SubtitleAuditEpisode::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => SubtitleAuditBatchStatus::class,
            'search_deadline_at' => 'immutable_datetime',
            'handed_off_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
