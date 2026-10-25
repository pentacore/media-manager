<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ServerIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One problem on a server, identified by its key while open (a partial unique index allows a single open row per server and key).
 *
 * @property int $id
 * @property int $server_id
 * @property string $key
 * @property IncidentSeverity $severity
 * @property IncidentStatus $status
 * @property string $title
 * @property string|null $detail
 * @property array<string, mixed> $context
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $last_seen_at
 * @property int $clear_streak
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $resolved_at
 * @property IncidentSeverity|null $notified_severity
 * @property CarbonImmutable|null $resolve_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 *
 * @method static ServerIncidentFactory factory($count = null, $state = [])
 * @method static Builder<static>|ServerIncident newModelQuery()
 * @method static Builder<static>|ServerIncident newQuery()
 * @method static Builder<static>|ServerIncident open()
 * @method static Builder<static>|ServerIncident query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'server_id',
    'key',
    'severity',
    'status',
    'title',
    'detail',
    'context',
    'opened_at',
    'last_seen_at',
    'clear_streak',
    'escalated_at',
    'resolved_at',
    'notified_severity',
    'resolve_notified_at',
])]
class ServerIncident extends Model
{
    /** @use HasFactory<ServerIncidentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'notified_severity' => IncidentSeverity::class,
            'context' => 'array',
            'opened_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolve_notified_at' => 'immutable_datetime',
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::Open->value);
    }
}
