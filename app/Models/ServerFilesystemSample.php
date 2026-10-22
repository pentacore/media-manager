<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ServerFilesystemSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One mounted filesystem's usage at one moment, stored per rollup resolution (60 s, 300 s, 3600 s).
 *
 * @property int $id
 * @property int $server_id
 * @property int $resolution
 * @property CarbonImmutable $recorded_at
 * @property string $mount
 * @property string|null $fstype
 * @property int $total_bytes
 * @property int $used_bytes
 * @property int $available_bytes
 * @property-read Server $server
 *
 * @method static ServerFilesystemSampleFactory factory($count = null, $state = [])
 * @method static Builder<static>|ServerFilesystemSample newModelQuery()
 * @method static Builder<static>|ServerFilesystemSample newQuery()
 * @method static Builder<static>|ServerFilesystemSample query()
 */
#[Fillable([
    'server_id',
    'resolution',
    'recorded_at',
    'mount',
    'fstype',
    'total_bytes',
    'used_bytes',
    'available_bytes',
])]
#[WithoutTimestamps]
class ServerFilesystemSample extends Model
{
    /** @use HasFactory<ServerFilesystemSampleFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
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
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return ServerMetricSample::whereExpired(static::query());
    }
}
