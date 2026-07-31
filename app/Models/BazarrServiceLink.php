<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BazarrServiceRole;
use Carbon\CarbonImmutable;
use Database\Factories\BazarrServiceLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $bazarr_connection_id
 * @property int $related_connection_id
 * @property BazarrServiceRole $role
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection $bazarrConnection
 * @property-read ServiceConnection $relatedConnection
 *
 * @method static BazarrServiceLinkFactory factory($count = null, $state = [])
 * @method static Builder<static>|BazarrServiceLink newModelQuery()
 * @method static Builder<static>|BazarrServiceLink newQuery()
 * @method static Builder<static>|BazarrServiceLink query()
 * @method static Builder<static>|BazarrServiceLink whereBazarrConnectionId($value)
 * @method static Builder<static>|BazarrServiceLink whereCreatedAt($value)
 * @method static Builder<static>|BazarrServiceLink whereId($value)
 * @method static Builder<static>|BazarrServiceLink whereRelatedConnectionId($value)
 * @method static Builder<static>|BazarrServiceLink whereRole($value)
 * @method static Builder<static>|BazarrServiceLink whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['bazarr_connection_id', 'related_connection_id', 'role'])]
class BazarrServiceLink extends Model
{
    /** @use HasFactory<BazarrServiceLinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'role' => BazarrServiceRole::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function bazarrConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class, 'bazarr_connection_id');
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function relatedConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class, 'related_connection_id');
    }
}
