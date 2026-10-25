<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentReportRequest;
use App\Jobs\ProcessAgentReport;
use App\Models\Server;
use App\Services\HostMonitoring\AgentConfigResponse;
use App\Services\HostMonitoring\AgentReport;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * Stores the latest snapshot at once (the admin pages read it), queues the samples, check results and rules, and answers with the agent's config.
     */
    public function __invoke(AgentReportRequest $request, AgentConfigResponse $agentConfigResponse): JsonResponse
    {
        /** @var Server $server */
        $server = $request->attributes->get('server');
        $validated = $request->validated();
        $receivedAt = now()->startOfSecond();
        $agentReport = AgentReport::fromPayload($validated, $receivedAt);

        $server->forceFill([
            'hostname' => $agentReport->host['hostname'],
            'os' => $agentReport->host['os'],
            'kernel' => $agentReport->host['kernel'],
            'arch' => $agentReport->host['arch'],
            'agent_version' => $agentReport->agentVersion,
            'last_report_at' => $receivedAt,
            'last_report' => $agentReport->snapshot(),
        ])->save();

        dispatch(new ProcessAgentReport($server->id, $validated, $receivedAt->toIso8601String()));

        return response()->json($agentConfigResponse->for($server, $agentReport->configVersion));
    }
}
