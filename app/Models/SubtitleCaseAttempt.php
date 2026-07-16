<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use Carbon\CarbonImmutable;
use Database\Factories\SubtitleCaseAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use JsonException;
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'candidate_count' => 0,
        'eligible_candidate_count' => 0,
    ];

    private const int MAX_SUMMARY_BYTES = 4_000;

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

    protected function summary(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $this->encodeCompactSummary($value),
        );
    }

    private function encodeCompactSummary(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Subtitle case attempt summary must be an object or null.');
        }

        foreach ($value as $summaryValue) {
            if ($summaryValue !== null && ! is_scalar($summaryValue)) {
                throw new InvalidArgumentException('Subtitle case attempt summary values must be scalar or null.');
            }
        }

        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException('Subtitle case attempt summary must be an object.');
        }

        foreach (array_keys($value) as $summaryKey) {
            if (! is_string($summaryKey)) {
                throw new InvalidArgumentException('Subtitle case attempt summary must be an object.');
            }
        }

        try {
            $encoded = $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException(
                'Subtitle case attempt summary must be JSON encodable.',
                previous: $jsonException,
            );
        }

        if (strlen($encoded) > self::MAX_SUMMARY_BYTES) {
            throw new InvalidArgumentException('Subtitle case attempt summary cannot exceed 4000 encoded bytes.');
        }

        return $encoded;
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
