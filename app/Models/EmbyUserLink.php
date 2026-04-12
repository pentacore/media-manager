<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmbyUserLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
