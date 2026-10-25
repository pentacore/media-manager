<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HostCheckStatus;
use App\Enums\HostCheckType;
use App\Observers\ServerCheckObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ServerCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A configured health check the agent runs on a server.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property HostCheckType $type
 * @property array<string, mixed> $params
 * @property bool $is_enabled
 * @property HostCheckStatus|null $last_status
 * @property string|null $last_message
 * @property array<string, mixed>|null $last_observed
 * @property CarbonImmutable|null $last_evaluated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 *
 * @method static ServerCheckFactory factory($count = null, $state = [])
 * @method static Builder<static>|ServerCheck newModelQuery()
 * @method static Builder<static>|ServerCheck newQuery()
 * @method static Builder<static>|ServerCheck query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(ServerCheckObserver::class)]
#[Fillable([
    'server_id',
    'type',
    'params',
    'is_enabled',
    'last_status',
    'last_message',
    'last_observed',
    'last_evaluated_at',
])]
class ServerCheck extends Model
{
    /** @use HasFactory<ServerCheckFactory> */
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
            'type' => HostCheckType::class,
            'params' => 'array',
            'is_enabled' => 'boolean',
            'last_status' => HostCheckStatus::class,
            'last_observed' => 'array',
            'last_evaluated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function label(): string
    {
        return sprintf('%s: %s', $this->type->label(), $this->type->subject($this->params));
    }

    public function incidentKey(): string
    {
        return sprintf('check:%s', $this->uuid);
    }
}
