<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask;
use App\Enums\BenchmarkScoringType;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * A reusable benchmark input for one AI task, with its fixtures and scoring rule.
 *
 * @property int $id
 * @property AiTask $task
 * @property string $name
 * @property list<string> $tags
 * @property string|null $notes
 * @property array<string, mixed> $input
 * @property list<array<string, mixed>> $fixtures
 * @property array<string, mixed> $scoring
 * @property string|null $source_invocation_id
 * @property int|null $source_prompt_tokens
 * @property int|null $source_completion_tokens
 * @property bool $source_truncated
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, BenchmarkResult> $results
 *
 * @method static BenchmarkCaseFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkCase newModelQuery()
 * @method static Builder<static>|BenchmarkCase newQuery()
 * @method static Builder<static>|BenchmarkCase query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'task',
    'name',
    'tags',
    'notes',
    'input',
    'fixtures',
    'scoring',
    'source_invocation_id',
    'source_prompt_tokens',
    'source_completion_tokens',
    'source_truncated',
])]
class BenchmarkCase extends Model
{
    /** @use HasFactory<BenchmarkCaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'tags' => 'array',
            'input' => 'array',
            'fixtures' => 'array',
            'scoring' => 'array',
            'source_truncated' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BenchmarkResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(BenchmarkResult::class);
    }

    /**
     * Whether the blind LLM judge scores this case: its own scoring type, or
     * its task's default when it names none.
     */
    public function isJudged(): bool
    {
        $scoringType = BenchmarkScoringType::tryFrom((string) ($this->scoring['type'] ?? '')) ?? $this->task->benchmarkScoringType();

        return $scoringType === BenchmarkScoringType::Judge;
    }
}
