<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use Carbon\CarbonImmutable;
use Database\Factories\ClassificationOutcomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property ClassificationGate $gate
 * @property string $subject_key
 * @property string $question
 * @property string|null $predicted
 * @property float|null $probability
 * @property float|null $threshold
 * @property ClassificationVerdict $verdict
 * @property bool|null $outcome_positive
 * @property string|null $outcome_detail
 * @property CarbonImmutable|null $outcome_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static ClassificationOutcomeFactory factory($count = null, $state = [])
 * @method static Builder<static>|ClassificationOutcome newModelQuery()
 * @method static Builder<static>|ClassificationOutcome newQuery()
 * @method static Builder<static>|ClassificationOutcome query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['gate', 'subject_key', 'question', 'predicted', 'probability', 'threshold', 'verdict', 'outcome_positive', 'outcome_detail', 'outcome_at'])]
class ClassificationOutcome extends Model
{
    /** @use HasFactory<ClassificationOutcomeFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * The subject key a gate writes and its outcome hook later resolves, e.g.
     * "decision:123", "subtitle_case:45" or "download:sonarr:abc".
     */
    public static function subjectKey(string $type, string|int $id): string
    {
        return sprintf('%s:%s', $type, $id);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'gate' => ClassificationGate::class,
            'verdict' => ClassificationVerdict::class,
            'probability' => 'float',
            'threshold' => 'float',
            'outcome_positive' => 'boolean',
            'outcome_at' => 'immutable_datetime',
        ];
    }

    /**
     * Retention window from mediamanager.retention (0 disables pruning).
     */
    public function prunable(): Builder
    {
        $days = (int) config('mediamanager.retention.classification_outcomes_days');

        return static::query()->when(
            $days > 0,
            fn (Builder $builder): Builder => $builder->where('created_at', '<', now()->subDays($days)),
            fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}
