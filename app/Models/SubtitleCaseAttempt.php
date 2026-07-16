<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use Carbon\CarbonImmutable;
use Database\Factories\SubtitleCaseAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $subtitle_case_id
 * @property int|null $action_request_id
 * @property SubtitleCaseAttemptType $type
 * @property int $candidate_count
 * @property int $eligible_candidate_count
 * @property array<string, mixed>|null $summary
 * @property SubtitleCaseAttemptOutcome $outcome
 * @property string|null $error_category
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubtitleCase $subtitleCase
 * @property-read ActionRequest|null $actionRequest
 *
 * @method static SubtitleCaseAttemptFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'subtitle_case_id',
    'action_request_id',
    'type',
    'candidate_count',
    'eligible_candidate_count',
    'summary',
    'outcome',
    'error_category',
    'started_at',
    'completed_at',
])]
class SubtitleCaseAttempt extends Model
{
    /** @use HasFactory<SubtitleCaseAttemptFactory> */
    use HasFactory;

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
            'type' => SubtitleCaseAttemptType::class,
            'candidate_count' => 'integer',
            'eligible_candidate_count' => 'integer',
            'summary' => 'array',
            'outcome' => SubtitleCaseAttemptOutcome::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
