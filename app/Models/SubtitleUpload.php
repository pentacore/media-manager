<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubtitleUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $subtitle_case_id
 * @property int|null $action_request_id
 * @property string $path
 * @property string $display_name
 * @property string $checksum
 * @property string $mime_type
 * @property string $format
 * @property int $size_bytes
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $cleaned_up_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $owner
 * @property-read SubtitleCase $subtitleCase
 * @property-read ActionRequest|null $actionRequest
 *
 * @method static SubtitleUploadFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'subtitle_case_id',
    'action_request_id',
    'path',
    'display_name',
    'checksum',
    'mime_type',
    'format',
    'size_bytes',
    'expires_at',
    'consumed_at',
    'cancelled_at',
    'cleaned_up_at',
])]
#[Hidden(['path'])]
class SubtitleUpload extends Model
{
    /** @use HasFactory<SubtitleUploadFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<SubtitleCase, $this>
     */
    public function subtitleCase(): BelongsTo
    {
        return $this->belongsTo(SubtitleCase::class);
    }

    /**
     * @return BelongsTo<ActionRequest, $this>
     */
    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cleaned_up_at' => 'immutable_datetime',
        ];
    }
}
