<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\ServerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * A host running the monitoring agent. The bigint key stays the primary key; the uuid is the public identity the agent token carries.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $hostname
 * @property string|null $os
 * @property string|null $kernel
 * @property string|null $arch
 * @property string|null $agent_version
 * @property string|null $token_hash
 * @property string|null $enrollment_token_hash
 * @property CarbonImmutable|null $enrollment_expires_at
 * @property CarbonImmutable|null $enrolled_at
 * @property CarbonImmutable|null $last_report_at
 * @property array<string, mixed>|null $last_report
 * @property string|null $last_report_error
 * @property CarbonImmutable|null $last_report_error_at
 * @property array<string, mixed> $rule_state
 * @property int $config_version
 * @property int $report_interval_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $open_incidents_count Present after withStatus()
 * @property bool|null $silent_incident_exists Present after withStatus()
 * @property-read Collection<int, ServerCheck> $checks
 * @property-read Collection<int, ServerIncident> $incidents
 *
 * @method static ServerFactory factory($count = null, $state = [])
 * @method static Builder<static>|Server newModelQuery()
 * @method static Builder<static>|Server newQuery()
 * @method static Builder<static>|Server query()
 * @method static Builder<static>|Server withStatus()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name',
    'hostname',
    'os',
    'kernel',
    'arch',
    'agent_version',
    'token_hash',
    'enrollment_token_hash',
    'enrollment_expires_at',
    'enrolled_at',
    'last_report_at',
    'last_report',
    'last_report_error',
    'last_report_error_at',
    'rule_state',
    'config_version',
    'report_interval_seconds',
])]
#[Hidden(['token_hash', 'enrollment_token_hash', 'rule_state'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return list<string>
     */
    #[Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'enrollment_expires_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime',
            'last_report_at' => 'immutable_datetime',
            'last_report' => 'array',
            'last_report_error_at' => 'immutable_datetime',
            'rule_state' => 'array',
        ];
    }

    /**
     * @return HasMany<ServerCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ServerCheck::class);
    }

    /**
     * @return HasMany<ServerIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(ServerIncident::class);
    }

    public function isEnrolled(): bool
    {
        return $this->enrolled_at !== null;
    }

    public function enrollmentIsUsable(): bool
    {
        return $this->enrollment_expires_at !== null && $this->enrollment_expires_at->isFuture();
    }

    /**
     * Adds the counts status() reads: open incidents and whether a `silent` one is among them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeWithStatus(Builder $query): Builder
    {
        return $query
            ->withCount(['incidents as open_incidents_count' => fn (Builder $incidents): Builder => $incidents->where('status', IncidentStatus::Open->value)])
            ->withExists(['incidents as silent_incident_exists' => fn (Builder $incidents): Builder => $incidents->where('status', IncidentStatus::Open->value)->where('key', 'silent')]);
    }

    /**
     * Adds the withStatus() counts to a server already in hand (a route-bound one) in one query that reads only its key, not the whole row again.
     */
    public function loadStatus(): static
    {
        $aggregates = static::query()->select($this->getKeyName())->whereKey($this->getKey())->withStatus()->firstOrFail();

        return $this->forceFill([
            'open_incidents_count' => $aggregates->open_incidents_count,
            'silent_incident_exists' => $aggregates->silent_incident_exists,
        ])->syncOriginalAttributes(['open_incidents_count', 'silent_incident_exists']);
    }

    /**
     * Requires the withStatus() scope on the query that loaded this server, or loadStatus() on it. An enrolled server
     * whose reports never got through (every one rejected) is silent once the sweep says so, not pending forever.
     */
    public function status(): ServerStatus
    {
        return match (true) {
            ! $this->isEnrolled() => ServerStatus::Pending,
            (bool) $this->silent_incident_exists => ServerStatus::Silent,
            $this->last_report_at === null => ServerStatus::Pending,
            (int) $this->open_incidents_count > 0 => ServerStatus::Incident,
            default => ServerStatus::Ok,
        };
    }
}
