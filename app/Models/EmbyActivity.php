<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmbyActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $emby_user_link_id
 * @property string $media_type
 * @property string $media_title
 * @property string|null $series_title
 * @property string $emby_item_id
 * @property string $action
 * @property int|null $duration_ticks
 * @property int|null $play_position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EmbyUserLink $embyUserLink
 *
 * @method static EmbyActivityFactory factory($count = null, $state = [])
 * @method static Builder<static>|EmbyActivity newModelQuery()
 * @method static Builder<static>|EmbyActivity newQuery()
 * @method static Builder<static>|EmbyActivity query()
 * @method static Builder<static>|EmbyActivity whereAction($value)
 * @method static Builder<static>|EmbyActivity whereCreatedAt($value)
 * @method static Builder<static>|EmbyActivity whereDurationTicks($value)
 * @method static Builder<static>|EmbyActivity whereEmbyItemId($value)
 * @method static Builder<static>|EmbyActivity whereEmbyUserLinkId($value)
 * @method static Builder<static>|EmbyActivity whereId($value)
 * @method static Builder<static>|EmbyActivity whereMediaTitle($value)
 * @method static Builder<static>|EmbyActivity whereMediaType($value)
 * @method static Builder<static>|EmbyActivity wherePlayPosition($value)
 * @method static Builder<static>|EmbyActivity whereSeriesTitle($value)
 * @method static Builder<static>|EmbyActivity whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
