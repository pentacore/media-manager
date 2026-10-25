<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Events\ServerReported;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\CheckResultRecorder;
use App\Services\HostMonitoring\IncidentTracker;
use App\Services\HostMonitoring\MetricSampleWriter;
use App\Services\HostMonitoring\ReportRuleEngine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Files an agent report's samples, check results and incidents. Inbound machine payloads ride the webhooks lane, which is drained before `default`.
 * The server row lock serializes one server's reports; a report received before the last processed one still stores its samples but runs no rules.
 */
#[Queue(QueueLane::Webhooks)]
class ProcessAgentReport implements ShouldQueue
{
    use Queueable;

    /** How far a stored processed_at may sit in the future before it is put down to the platform clock having jumped back. */
    private const int CLOCK_TOLERANCE_MINUTES = 5;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $payload  AgentReportRequest::validated()
     * @param  string  $receivedAt  ISO-8601 time the report endpoint received it
     */
    public function __construct(public int $serverId, public array $payload, public string $receivedAt) {}

    public function handle(
        MetricSampleWriter $metricSampleWriter,
        CheckResultRecorder $checkResultRecorder,
        ReportRuleEngine $reportRuleEngine,
        IncidentTracker $incidentTracker,
    ): void {
        DB::transaction(function () use ($metricSampleWriter, $checkResultRecorder, $reportRuleEngine, $incidentTracker): void {
            $server = Server::query()->whereKey($this->serverId)->lockForUpdate()->first();

            if ($server === null) {
                return;
            }

            $agentReport = AgentReport::fromPayload($this->payload, CarbonImmutable::parse($this->receivedAt));

            $metricSampleWriter->write($server, $agentReport);

            DB::afterCommit(fn () => event(new ServerReported($server->id, $server->last_report_at?->toIso8601String())));

            if ($this->isStale($server, $agentReport)) {
                return;
            }

            $checkResultRecorder->record($server, $agentReport);
            $incidentTracker->clear($server, 'silent', 1);
            $reportRuleEngine->evaluate($server, $agentReport);

            $server->rule_state = [...$server->rule_state, 'processed_at' => $agentReport->receivedAt->toIso8601String()];
            $server->save();
        });
    }

    /**
     * Compared as dates: the stored ISO string and Carbon's `Y-m-d H:i:s` cast do not sort together.
     * A processed_at further ahead of now() than the tolerance means the platform clock went back; trusting it would
     * silence every rule until the clock caught up, so the report is processed and overwrites it.
     */
    private function isStale(Server $server, AgentReport $agentReport): bool
    {
        $storedProcessedAt = $server->rule_state['processed_at'] ?? null;

        if (! is_string($storedProcessedAt)) {
            return false;
        }

        $processedAt = CarbonImmutable::parse($storedProcessedAt);

        if ($processedAt->greaterThan(now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
            Log::warning('Agent report processed_at is ahead of the platform clock; processing the report anyway.', [
                'server_id' => $server->id,
                'processed_at' => $storedProcessedAt,
                'received_at' => $agentReport->receivedAt->toIso8601String(),
            ]);

            return false;
        }

        return $agentReport->receivedAt->lessThan($processedAt);
    }
}
