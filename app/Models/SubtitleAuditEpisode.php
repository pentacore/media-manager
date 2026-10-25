<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubtitleAuditEpisodeOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\SubtitleAuditEpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $subtitle_audit_batch_id
 * @property int $episode_id
 * @property int $season_number
 * @property int $episode_number
 * @property list<string> $missing_languages
 * @property SubtitleAuditEpisodeOutcome $outcome
 * @property int|null $action_request_id
 * @property string|null $release_title
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubtitleAuditBatch $batch
 * @property-read ActionRequest|null $actionRequest
 *
 * @method static SubtitleAuditEpisodeFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'subtitle_audit_batch_id',
    'episode_id',
    'season_number',
    'episode_number',
    'missing_languages',
    'outcome',
    'action_request_id',
    'release_title',
    'failure_reason',
])]
class SubtitleAuditEpisode extends Model
{
    /** @use HasFactory<SubtitleAuditEpisodeFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    #[Override]
    protected $attributes = [
        'outcome' => 'searching',
    ];

    /**
     * @return BelongsTo<SubtitleAuditBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SubtitleAuditBatch::class, 'subtitle_audit_batch_id');
    }

    /**
     * @return BelongsTo<ActionRequest, $this>
     */
    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class);
    }

    public function label(): string
    {
        return sprintf('S%02dE%02d', $this->season_number, $this->episode_number);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'missing_languages' => 'array',
            'outcome' => SubtitleAuditEpisodeOutcome::class,
        ];
    }
}
