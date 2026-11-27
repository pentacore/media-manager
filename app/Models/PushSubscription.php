<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One browser's Web Push subscription for a user. `vapid_public_key` records
 * the server key the browser subscribed with, so rows made before a key
 * rotation can be told apart and dropped.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string $vapid_public_key
 * @property string|null $label
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static PushSubscriptionFactory factory($count = null, $state = [])
 * @method static Builder<static>|PushSubscription newModelQuery()
 * @method static Builder<static>|PushSubscription newQuery()
 * @method static Builder<static>|PushSubscription query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'endpoint',
    'public_key',
    'auth_token',
    'content_encoding',
    'vapid_public_key',
    'label',
    'last_used_at',
])]
#[Hidden(['auth_token', 'public_key'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
