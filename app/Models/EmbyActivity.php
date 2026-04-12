<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmbyActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['emby_user_link_id', 'media_type', 'media_title', 'series_title', 'emby_item_id', 'action', 'duration_ticks', 'play_position'])]
class EmbyActivity extends Model
{
    /** @use HasFactory<EmbyActivityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<EmbyUserLink, $this>
     */
    public function embyUserLink(): BelongsTo
    {
        return $this->belongsTo(EmbyUserLink::class);
    }
}
