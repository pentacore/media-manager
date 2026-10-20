<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AiReasoningLevel;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A scheduled benchmark run found a regressed model, or could not start.
 */
class ScheduledBenchmarkAlert extends Notification
{
    use Queueable;

    private const array METRIC_LABELS = ['pass_rate' => 'pass rate', 'avg_score' => 'score', 'error_rate' => 'errors'];

    private function __construct(
        public readonly string $kind,
        public readonly string $scheduleName,
        public readonly string $message,
        public readonly string $link,
    ) {}

    /**
     * @param  list<array{candidate: array{provider: string, model: string, reasoning: string|null}, paired_cases: int, metrics: list<array{metric: string, before: float, after: float}>}>  $regressions
     */
    public static function regression(BenchmarkSchedule $benchmarkSchedule, BenchmarkRun $benchmarkRun, array $regressions): self
    {
        $clauses = array_map(self::describe(...), $regressions);

        return new self('regression', $benchmarkSchedule->name, implode('; ', $clauses), route('admin.benchmarks.runs.show', $benchmarkRun, absolute: false));
    }

    public static function skipped(BenchmarkSchedule $benchmarkSchedule, string $reason): self
    {
        return new self('skipped', $benchmarkSchedule->name, $reason, route('admin.benchmarks.schedules.index', absolute: false));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return resolve(PreferenceResolver::class)->channelsFor($notifiable, self::class, 'warning');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'service' => 'system',
            'title' => $this->kind === 'regression'
                ? sprintf('Benchmark regression: %s', $this->scheduleName)
                : sprintf('Scheduled benchmark skipped: %s', $this->scheduleName),
            'message' => $this->message,
            'link' => $this->link,
            'kind' => $this->kind,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage(severity: 'warning', title: $data['title'], body: $data['message'], url: url($this->link));
    }

    /**
     * @param  array{candidate: array{provider: string, model: string, reasoning: string|null}, paired_cases: int, metrics: list<array{metric: string, before: float, after: float}>}  $regression
     */
    private static function describe(array $regression): string
    {
        $candidate = $regression['candidate'];
        $reasoning = AiReasoningLevel::tryFrom((string) $candidate['reasoning']);
        $name = sprintf('%s/%s', $candidate['provider'], $candidate['model'])
            .($reasoning instanceof AiReasoningLevel && $reasoning !== AiReasoningLevel::ProviderDefault ? sprintf(' (%s)', $reasoning->label()) : '');

        $metrics = array_map(
            static fn (array $metric): string => sprintf('%s %d%% → %d%%', self::METRIC_LABELS[$metric['metric']] ?? $metric['metric'], (int) round($metric['before'] * 100), (int) round($metric['after'] * 100)),
            $regression['metrics'],
        );

        return sprintf('%s: %s', $name, implode(', ', $metrics));
    }
}
