<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Models\Server;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\CheckResultRecorder;
use App\Services\HostMonitoring\MetricSampleWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;

/**
 * Files an agent report's samples and check results. Inbound machine payloads ride the webhooks lane, which is drained before `default`.
 * The server row lock serializes one server's reports; a report received before the last processed one still stores its samples but leaves results alone.
 */
#[Queue(QueueLane::Webhooks)]
class ProcessAgentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $payload  AgentReportRequest::validated()
     * @param  string  $receivedAt  ISO-8601 time the report endpoint received it
     */
    public function __construct(public int $serverId, public array $payload, public string $receivedAt) {}

    public function handle(MetricSampleWriter $metricSampleWriter, CheckResultRecorder $checkResultRecorder): void
    {
        DB::transaction(function () use ($metricSampleWriter, $checkResultRecorder): void {
            $server = Server::query()->whereKey($this->serverId)->lockForUpdate()->first();

            if ($server === null) {
                return;
            }

            $agentReport = AgentReport::fromPayload($this->payload, CarbonImmutable::parse($this->receivedAt));

            $metricSampleWriter->write($server, $agentReport);

            if ($this->isStale($server, $agentReport)) {
                return;
            }

            $checkResultRecorder->record($server, $agentReport);

            $server->rule_state = [...$server->rule_state, 'processed_at' => $agentReport->receivedAt->toIso8601String()];
            $server->save();
        });
    }

    /**
     * Compared as dates: the stored ISO string and Carbon's `Y-m-d H:i:s` cast do not sort together.
     */
    private function isStale(Server $server, AgentReport $agentReport): bool
    {
        $processedAt = $server->rule_state['processed_at'] ?? null;

        return is_string($processedAt) && $agentReport->receivedAt->lessThan(CarbonImmutable::parse($processedAt));
    }
}
