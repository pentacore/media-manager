<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TimeWindow;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Statistics\StatisticsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __invoke(Request $request, StatisticsRepository $statisticsRepository): Response
    {
        $timeWindow = TimeWindow::fromRequest($request->string('window')->toString(), TimeWindow::Last30d);

        $webhooksTotal = $statisticsRepository->total('webhooks.received', $timeWindow);
        $actionsByStatus = $statisticsRepository->breakdown('actions.by_status', $timeWindow, 'status');
        $agentDecisions = $statisticsRepository->breakdown('agent.decisions', $timeWindow, 'status');

        return Inertia::render('Admin/Statistics/Index', [
            'window' => $timeWindow->value,
            'windows' => TimeWindow::options(),
            'headline' => [
                'webhooks' => $webhooksTotal['count'],
                'actions' => $this->sumCounts($actionsByStatus),
                'approvalRate' => $this->approvalRate($actionsByStatus),
                'resolvedRate' => $this->resolvedRate($actionsByStatus),
                'agentNoActionRate' => $this->agentNoActionRate($agentDecisions),
            ],
            'webhookSeries' => $statisticsRepository->series('webhooks.received', $timeWindow),
            'webhooksByService' => $statisticsRepository->breakdown('webhooks.received', $timeWindow, 'service'),
            'actionsByStatus' => $actionsByStatus,
            'actionsByOrigin' => $statisticsRepository->breakdown('actions.by_status', $timeWindow, 'origin'),
            'agentDecisions' => $agentDecisions,
            'diskSeries' => $statisticsRepository->series('service.disk_free_bytes', $timeWindow),
            'queueSeries' => $statisticsRepository->series('queue.depth', $timeWindow),
            'sessionSeries' => $statisticsRepository->series('sessions.active', $timeWindow),
            'uptime' => $this->uptime($statisticsRepository, $timeWindow),
            'aiCostSeries' => $statisticsRepository->series('ai.usage', $timeWindow),
        ]);
    }

    /**
     * Total events across a breakdown's rows.
     *
     * @param  list<array{key: string, count: int, sum: float}>  $breakdown
     */
    private function sumCounts(array $breakdown): int
    {
        return (int) collect($breakdown)->sum('count');
    }

    /**
     * Share of decided actions (approved, executing, or completed) out of all
     * actions that were either approved or rejected, as a whole-number percent.
     * Rejected actions are the only "declined" outcome; pending actions have no
     * decision yet and are excluded from the denominator.
     *
     * @param  list<array{key: string, count: int, sum: float}>  $actionsByStatus
     */
    private function approvalRate(array $actionsByStatus): int
    {
        $byStatus = collect($actionsByStatus)->keyBy('key');

        $approved = (int) $byStatus->only(['approved', 'executing', 'completed'])->sum('count');
        $decided = $approved + (int) ($byStatus->get('rejected')['count'] ?? 0);

        if ($decided === 0) {
            return 0;
        }

        return (int) round(($approved / $decided) * 100);
    }

    /**
     * Share of actions that reached a terminal state (completed, failed, or
     * rejected) out of all actions in the window, as a whole-number percent.
     *
     * @param  list<array{key: string, count: int, sum: float}>  $actionsByStatus
     */
    private function resolvedRate(array $actionsByStatus): int
    {
        $byStatus = collect($actionsByStatus)->keyBy('key');
        $total = (int) $byStatus->sum('count');

        if ($total === 0) {
            return 0;
        }

        $terminal = (int) $byStatus->only(['completed', 'failed', 'rejected'])->sum('count');

        return (int) round(($terminal / $total) * 100);
    }

    /**
     * Share of agent decisions that resulted in no proposed action, as a
     * whole-number percent.
     *
     * @param  list<array{key: string, count: int, sum: float}>  $agentDecisions
     */
    private function agentNoActionRate(array $agentDecisions): int
    {
        $byStatus = collect($agentDecisions)->keyBy('key');
        $total = (int) $byStatus->sum('count');

        if ($total === 0) {
            return 0;
        }

        $noAction = (int) ($byStatus->get('no_action')['count'] ?? 0);

        return (int) round(($noAction / $total) * 100);
    }

    /**
     * Per-connection uptime and latency for the window. Uptime % is
     * sum/count*100; latency is the average latency sample. Connection names
     * are hydrated from the `service_connection_id` dimension.
     *
     * @return list<array{connection: string, uptime: float, latency: float|null}>
     */
    private function uptime(StatisticsRepository $statisticsRepository, TimeWindow $timeWindow): array
    {
        $uptimeRows = collect($statisticsRepository->breakdown('service.uptime_pct', $timeWindow, 'service_connection_id'));
        $latencyRows = collect($statisticsRepository->breakdown('service.latency_ms', $timeWindow, 'service_connection_id'))
            ->keyBy('key');

        if ($uptimeRows->isEmpty()) {
            return [];
        }

        $ids = $uptimeRows->pluck('key')->map(fn (string $id): int => (int) $id)->all();

        /** @var Collection<int, string> $names */
        $names = ServiceConnection::query()->findMany($ids)->pluck('name', 'id');

        return $uptimeRows
            ->map(function (array $row) use ($names, $latencyRows): array {
                $id = (int) $row['key'];
                $count = $row['count'];
                $latency = $latencyRows->get($row['key']);
                $latencyCount = $latency['count'] ?? 0;

                return [
                    'connection' => (string) ($names->get($id) ?? "#{$id}"),
                    'uptime' => $count > 0 ? round(($row['sum'] / $count) * 100, 1) : 0.0,
                    'latency' => $latencyCount > 0 ? round($latency['sum'] / $latencyCount, 1) : null,
                ];
            })
            ->values()
            ->all();
    }
}
