<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * The text-generation workloads that carry their own model and reasoning
 * selection (Admin → AI Models). Classification, reranking and embeddings
 * are not tasks: they are configured on AI Settings.
 */
enum AiTask: string
{
    use EnumUtils;

    case Chat = 'chat';
    case Title = 'title';
    case Decision = 'decision';
    case DecisionVerifier = 'decision_verifier';
    case FileInspector = 'file_inspector';
    case StuckDownloadInvestigator = 'stuck_download_investigator';
    case PriceUpdater = 'price_updater';
    case BenchmarkJudge = 'benchmark_judge';
    case Failover = 'failover';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Title => 'Conversation titles',
            self::Decision => 'Decision agent',
            self::DecisionVerifier => 'Decision verifier',
            self::FileInspector => 'File inspector',
            self::StuckDownloadInvestigator => 'Stuck download investigator',
            self::PriceUpdater => 'Price updater',
            self::BenchmarkJudge => 'Benchmark judge',
            self::Failover => 'Failover',
        };
    }

    /**
     * Whether the task takes per-webhook-event override rows.
     */
    public function acceptsEventScopes(): bool
    {
        return in_array($this, [self::Decision, self::DecisionVerifier], true);
    }

    /**
     * The tasks that take per-webhook-event override rows, in enum order.
     *
     * @return list<self>
     */
    public static function eventScoped(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $aiTask): bool => $aiTask->acceptsEventScopes()));
    }

    /**
     * Whether a null model on the task's default row follows the chat model.
     */
    public function inheritsChatModel(): bool
    {
        return in_array($this, [self::Decision, self::DecisionVerifier, self::FileInspector, self::StuckDownloadInvestigator, self::PriceUpdater, self::BenchmarkJudge], true);
    }

    /**
     * Whether the task's tier list may hold more than one tier. The benchmark
     * judge is a single selection so every run is judged by the same model.
     */
    public function allowsTiers(): bool
    {
        return $this !== self::BenchmarkJudge;
    }

    /**
     * Whether the task has a reasoning setting of its own. Failover runs at
     * the failing task's level.
     */
    public function hasReasoning(): bool
    {
        return $this !== self::Failover;
    }

    /**
     * Whether the task is a workload a benchmark case can target.
     */
    public function isBenchmarkable(): bool
    {
        return ! in_array($this, [self::Failover, self::BenchmarkJudge], true);
    }

    /**
     * How a benchmark case for the task is graded.
     */
    public function benchmarkScoringType(): BenchmarkScoringType
    {
        return match ($this) {
            self::Decision => BenchmarkScoringType::Actions,
            self::PriceUpdater => BenchmarkScoringType::Prices,
            self::DecisionVerifier => BenchmarkScoringType::Verdict,
            default => BenchmarkScoringType::Judge,
        };
    }
}
