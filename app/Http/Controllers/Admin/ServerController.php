<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServerRequest;
use App\Models\Server;
use App\Services\Audit\AuditLogger;
use App\Services\HostMonitoring\AgentRelease;
use App\Services\HostMonitoring\EnrollmentInstructions;
use App\Services\HostMonitoring\ServerEnrollment;
use App\Settings\MonitoringSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(MonitoringSettings $monitoringSettings): Response
    {
        $lengthAwarePaginator = Server::query()
            ->withStatus()
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $pushUrlHint = $monitoringSettings->pushUrlHint();

        return Inertia::render('Admin/Servers/Index', [
            'servers' => [
                'data' => $lengthAwarePaginator->getCollection()->map($this->row(...))->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'latestAgentVersion' => AgentRelease::latest(),
            'watcher' => [
                'push_url_set' => $pushUrlHint !== null,
                'push_url_hint' => $pushUrlHint,
            ],
        ]);
    }

    public function store(
        StoreServerRequest $storeServerRequest,
        ServerEnrollment $serverEnrollment,
        EnrollmentInstructions $enrollmentInstructions,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $storeServerRequest->validated();

        /** @var array{server: Server, token: string} $created */
        $created = DB::transaction(function () use ($validated, $serverEnrollment, $auditLogger): array {
            $server = Server::query()->create(['name' => $validated['name']]);
            $token = $serverEnrollment->issueToken($server);

            $auditLogger->record('server.created', $server, sprintf('Added server "%s".', $server->name));

            return ['server' => $server, 'token' => $token];
        });

        Inertia::flash('enrollment', $enrollmentInstructions->for($created['server'], $created['token']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server added. Install the agent with the command shown.')]);

        return to_route('admin.servers.show', $created['server']);
    }

    /**
     * Placeholder: the server's facts only. The metrics, checks and incidents arrive with the full server page.
     */
    public function show(Server $server): Response
    {
        $server = Server::query()->withStatus()->findOrFail($server->id);
        $serverStatus = $server->status();

        return Inertia::render('Admin/Servers/Show', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'os' => $server->os,
                'arch' => $server->arch,
                'agent_version' => $server->agent_version,
                'status' => $serverStatus->value,
                'status_label' => $serverStatus->label(),
                'enrolled_at' => $server->enrolled_at?->toIso8601String(),
                'last_report_at' => $server->last_report_at?->toIso8601String(),
                'enrollment_expires_at' => $server->enrollment_expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Issues a fresh enrollment token. The running agent keeps its own token until an enrollment with the new one replaces it.
     */
    public function regenerateEnrollment(
        Server $server,
        ServerEnrollment $serverEnrollment,
        EnrollmentInstructions $enrollmentInstructions,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $token = DB::transaction(function () use ($server, $serverEnrollment, $auditLogger): string {
            $token = $serverEnrollment->issueToken($server);

            $auditLogger->record('server.enrollment_regenerated', $server, sprintf('Created a new enrollment token for server "%s".', $server->name));

            return $token;
        });

        Inertia::flash('enrollment', $enrollmentInstructions->for($server, $token));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New enrollment token created.')]);

        return back();
    }

    /**
     * Deleting the row cascades its checks, incidents and samples; the agent token goes with it.
     */
    public function destroy(Server $server, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($server, $auditLogger): void {
            $server->delete();

            $auditLogger->record('server.deleted', $server, sprintf('Removed server "%s".', $server->name), context: ['server_id' => $server->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server removed.')]);

        return to_route('admin.servers.index');
    }

    /**
     * @return array{id: int, name: string, hostname: ?string, status: string, status_label: string, last_report_at: ?string, agent_version: ?string, agent_outdated: bool, open_incidents_count: int}
     */
    private function row(Server $server): array
    {
        $serverStatus = $server->status();

        return [
            'id' => $server->id,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'status' => $serverStatus->value,
            'status_label' => $serverStatus->label(),
            'last_report_at' => $server->last_report_at?->toIso8601String(),
            'agent_version' => $server->agent_version,
            'agent_outdated' => AgentRelease::isOutdated($server->agent_version),
            'open_incidents_count' => (int) $server->open_incidents_count,
        ];
    }
}
