<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmbyUserLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $emby_user_id
 * @property string $emby_username
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmbyActivity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\User $user
 * @method static \Database\Factories\EmbyUserLinkFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereEmbyUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereEmbyUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmbyUserLink whereUserId($value)
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'emby_user_id', 'emby_username'])]
class EmbyUserLink extends Model
{
    /** @use HasFactory<EmbyUserLinkFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EmbyActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(EmbyActivity::class);
    }
}
