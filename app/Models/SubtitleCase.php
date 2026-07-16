<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubtitleCaseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubtitleCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property int $bazarr_connection_id
 * @property int $service_connection_id
 * @property int|null $download_action_request_id
 * @property int|null $replacement_action_request_id
 * @property string $media_type
 * @property string $scope
 * @property array<string, mixed> $target_ids
 * @property string $file_fingerprint
 * @property array<int, array<string, mixed>> $required_languages
 * @property string $requirements_fingerprint
 * @property SubtitleCaseStatus $status
 * @property array<string, mixed>|null $evidence
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $grace_until
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection $bazarrConnection
 * @property-read ServiceConnection $serviceConnection
 * @property-read ActionRequest|null $downloadActionRequest
 * @property-read ActionRequest|null $replacementActionRequest
 * @property-read Collection<int, SubtitleCaseAttempt> $attempts
 * @property-read int|null $attempts_count
 * @property-read Collection<int, SubtitleUpload> $uploads
 * @property-read int|null $uploads_count
 *
 * @method static SubtitleCaseFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'bazarr_connection_id',
    'service_connection_id',
    'download_action_request_id',
    'replacement_action_request_id',
    'media_type',
    'scope',
    'target_ids',
    'file_fingerprint',
    'required_languages',
    'requirements_fingerprint',
    'status',
    'evidence',
    'failure_reason',
    'grace_until',
    'observed_at',
    'resolved_at',
    'superseded_at',
])]
class SubtitleCase extends Model
{
    /** @use HasFactory<SubtitleCaseFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'observing',
    ];

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
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return BelongsTo<ActionRequest, $this>
     */
    public function downloadActionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class, 'download_action_request_id');
    }

    /**
     * @return BelongsTo<ActionRequest, $this>
     */
    public function replacementActionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class, 'replacement_action_request_id');
    }

    /**
     * @return HasMany<SubtitleCaseAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(SubtitleCaseAttempt::class);
    }

    /**
     * @return HasMany<SubtitleUpload, $this>
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(SubtitleUpload::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'target_ids' => 'array',
            'required_languages' => 'array',
            'status' => SubtitleCaseStatus::class,
            'evidence' => 'array',
            'grace_until' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
