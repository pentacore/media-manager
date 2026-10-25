<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask;
use App\Enums\BenchmarkConversationMode;
use App\Enums\BenchmarkScoringType;
use App\Observers\BenchmarkCaseObserver;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property-read Collection<int, BenchmarkCaseAttachment> $attachments
 * @property-read int|null $attachments_count
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
#[ObservedBy(BenchmarkCaseObserver::class)]
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

    /**
     * @return HasMany<BenchmarkCaseAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BenchmarkCaseAttachment::class);
    }

    /**
     * The attachments of one user turn (null is the final prompt), oldest first.
     *
     * @return Collection<int, BenchmarkCaseAttachment>
     */
    public function attachmentsFor(?int $turn): Collection
    {
        $this->loadMissing('attachments');

        return $this->attachments
            ->filter(static fn (BenchmarkCaseAttachment $benchmarkCaseAttachment): bool => $benchmarkCaseAttachment->turn === $turn)
            ->sortBy('id')
            ->values();
    }

    /**
     * The user turns of the conversation in order: the history's user messages,
     * then the prompt as the final turn (null).
     *
     * @return list<array{turn: int|null, content: string}>
     */
    public function userTurns(): array
    {
        $turns = [];

        foreach ((array) ($this->input['history'] ?? []) as $index => $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $turns[] = ['turn' => (int) $index, 'content' => $message['content']];
            }
        }

        $turns[] = ['turn' => null, 'content' => (string) ($this->input['prompt'] ?? '')];

        return $turns;
    }

    /**
     * Only a chat case reads its replay mode; every other task runs as context.
     */
    public function conversationMode(): BenchmarkConversationMode
    {
        if ($this->task !== AiTask::Chat) {
            return BenchmarkConversationMode::Context;
        }

        return BenchmarkConversationMode::tryFrom((string) ($this->input['conversation'] ?? '')) ?? BenchmarkConversationMode::Context;
    }

    public function isScripted(): bool
    {
        return $this->conversationMode() === BenchmarkConversationMode::Scripted;
    }
}
