<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use App\Enums\MediaReplacementStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaReplacementAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $action_request_id
 * @property int|null $service_connection_id
 * @property MediaReplacementStatus $status
 * @property string $scope
 * @property array<array-key, mixed> $target
 * @property string $candidate_fingerprint
 * @property array<array-key, mixed> $candidate
 * @property array<array-key, mixed> $required_languages
 * @property string|null $download_id
 * @property array<array-key, mixed>|null $verification
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ActionRequest $actionRequest
 * @property-read ServiceConnection|null $serviceConnection
 *
 * @method static MediaReplacementAttemptFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'action_request_id',
    'service_connection_id',
    'status',
    'scope',
    'target',
    'candidate_fingerprint',
    'candidate',
    'required_languages',
    'download_id',
    'verification',
    'failure_reason',
    'started_at',
    'completed_at',
])]
class MediaReplacementAttempt extends Model
{
    /** @use HasFactory<MediaReplacementAttemptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ActionRequest, $this>
     */
    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class);
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MediaReplacementStatus::class,
            'target' => 'array',
            'candidate' => 'array',
            'required_languages' => 'array',
            'verification' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
