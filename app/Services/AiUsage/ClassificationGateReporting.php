<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Ai\Routing\ChatToolRouter;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Jobs\RunDecisionAgent;
use App\Models\ClassificationOutcome;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Per-gate calibration for the AI Usage page: how often each probability
 * band turned out positive, next to the gate's current threshold.
 */
final readonly class ClassificationGateReporting
{
    private const int BANDS = 10;

    public function __construct(private AiSettings $aiSettings) {}

    /**
     * @return list<array{gate: string, label: string, threshold: float, total: int, resolved: int, positive: int, verdicts: array<string, int>, audit_runs: int, audit_positive: int, bands: list<array{label: string, count: int, resolved: int, positive_rate: float|null}>}>
     */
    public function summary(?CarbonImmutable $since): array
    {
        $rows = ClassificationOutcome::query()
            ->when($since instanceof CarbonImmutable, fn (Builder $builder): Builder => $builder->where('created_at', '>=', $since))
            ->whereNotNull('probability')
            ->selectRaw('gate, verdict, LEAST(FLOOR(probability * ?), ?) AS band, COUNT(*) AS total, COUNT(outcome_at) AS resolved, SUM(CASE WHEN outcome_positive THEN 1 ELSE 0 END) AS positive', [self::BANDS, self::BANDS - 1])
            ->groupBy('gate', 'verdict', 'band')
            ->toBase()
            ->get();

        return array_map(
            fn (ClassificationGate $classificationGate): array => $this->gateSummary($classificationGate, $rows->where('gate', $classificationGate->value)),
            ClassificationGate::cases(),
        );
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array{gate: string, label: string, threshold: float, total: int, resolved: int, positive: int, verdicts: array<string, int>, audit_runs: int, audit_positive: int, bands: list<array{label: string, count: int, resolved: int, positive_rate: float|null}>}
     */
    private function gateSummary(ClassificationGate $classificationGate, Collection $rows): array
    {
        $bands = [];

        for ($band = 0; $band < self::BANDS; $band++) {
            $bandRows = $rows->filter(fn (stdClass $row): bool => (int) $row->band === $band);
            $resolved = (int) $bandRows->sum('resolved');

            $bands[] = [
                'label' => sprintf('%d–%d%%', $band * 10, ($band + 1) * 10),
                'count' => (int) $bandRows->sum('total'),
                'resolved' => $resolved,
                'positive_rate' => $resolved > 0 ? round((int) $bandRows->sum('positive') / $resolved, 4) : null,
            ];
        }

        $auditRows = $rows->where('verdict', ClassificationVerdict::AuditRun->value);

        return [
            'gate' => $classificationGate->value,
            'label' => $classificationGate->label(),
            'threshold' => $this->threshold($classificationGate),
            'total' => (int) $rows->sum('total'),
            'resolved' => (int) $rows->sum('resolved'),
            'positive' => (int) $rows->sum('positive'),
            'verdicts' => $rows->groupBy('verdict')->map(fn (Collection $verdictRows): int => (int) $verdictRows->sum('total'))->all(),
            'audit_runs' => (int) $auditRows->sum('total'),
            'audit_positive' => (int) $auditRows->sum('positive'),
            'bands' => $bands,
        ];
    }

    private function threshold(ClassificationGate $classificationGate): float
    {
        return match ($classificationGate) {
            ClassificationGate::DecisionGate => $this->aiSettings->decisionGateThreshold(),
            ClassificationGate::ActionKind => RunDecisionAgent::SCOPE_AT,
            ClassificationGate::StuckImport => $this->aiSettings->stuckImportThreshold(),
            ClassificationGate::SubtitleTriage => $this->aiSettings->subtitleTriageThreshold(),
            ClassificationGate::ChatRouting => ChatToolRouter::INCLUDE_AT,
        };
    }
}
