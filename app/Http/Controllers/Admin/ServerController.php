<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DbPingEngine;
use App\Enums\HostCheckType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\ServerMetricRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServerRequest;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use App\Services\Audit\AuditLogger;
use App\Services\HostMonitoring\AgentRelease;
use App\Services\HostMonitoring\EnrollmentInstructions;
use App\Services\HostMonitoring\ServerEnrollment;
use App\Services\HostMonitoring\ServerMetricRepository;
use App\Settings\MonitoringSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * The server page. Props are closures so a live reload rebuilds only the ones it names; the metrics load in their own deferred request.
     */
    public function show(Request $request, Server $server, ServerMetricRepository $serverMetricRepository): Response
    {
        $range = $request->query('range');
        $serverMetricRange = ServerMetricRange::fromRequest(is_string($range) ? $range : null);

        return Inertia::render('Admin/Servers/Show', [
            'server' => fn (): array => $this->facts($server->loadStatus()),
            'latest' => fn (): ?array => $this->latest($server),
            'checks' => fn (): array => $server->checks()->orderBy('id')->get()->map($this->check(...))->all(),
            'checkTypes' => array_map(
                static fn (HostCheckType $hostCheckType): array => ['value' => $hostCheckType->value, 'label' => $hostCheckType->label()],
                HostCheckType::cases(),
            ),
            'dbEngines' => array_map(
                static fn (DbPingEngine $dbPingEngine): array => ['value' => $dbPingEngine->value, 'label' => $dbPingEngine->label()],
                DbPingEngine::cases(),
            ),
            'incidents' => fn (): array => $this->incidents($server),
            'range' => $serverMetricRange->value,
            'ranges' => ServerMetricRange::options(),
            'metrics' => Inertia::defer(fn (): array => $serverMetricRepository->series($server, $serverMetricRange), 'metrics'),
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
     * @return array{id: int, uuid: string, name: string, hostname: ?string, os: ?string, kernel: ?string, arch: ?string, agent_version: ?string, agent_outdated: bool, status: string, status_label: string, enrolled_at: ?string, last_report_at: ?string, report_interval_seconds: int, uptime_seconds: ?int, enrollment_expires_at: ?string}
     */
    private function facts(Server $server): array
    {
        $serverStatus = $server->status();
        $uptimeSeconds = $server->last_report['uptime_seconds'] ?? null;

        return [
            'id' => $server->id,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'os' => $server->os,
            'kernel' => $server->kernel,
            'arch' => $server->arch,
            'agent_version' => $server->agent_version,
            'agent_outdated' => AgentRelease::isOutdated($server->agent_version),
            'status' => $serverStatus->value,
            'status_label' => $serverStatus->label(),
            'enrolled_at' => $server->enrolled_at?->toIso8601String(),
            'last_report_at' => $server->last_report_at?->toIso8601String(),
            'report_interval_seconds' => $server->report_interval_seconds,
            'uptime_seconds' => is_numeric($uptimeSeconds) ? (int) $uptimeSeconds : null,
            'enrollment_expires_at' => $server->enrollment_expires_at?->toIso8601String(),
        ];
    }

    /**
     * The latest report's figures. Byte and percent fields of an unresponsive filesystem are null.
     *
     * @return array{cpu_percent: float, load: list<float>, memory_used_percent: ?float, filesystems: list<array{mount: string, fstype: ?string, unresponsive: bool, used_percent: ?float, used_bytes: ?int, total_bytes: ?int, available_bytes: ?int}>, failed_units: ?list<string>, containers: ?list<array{name: string, state: string, health: ?string, restart_count: int}>}|null
     */
    private function latest(Server $server): ?array
    {
        /** @var array{cpu_percent: float, load: list<float>, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, fstype: ?string, unresponsive: bool, total_bytes: ?int, used_bytes: ?int, available_bytes: ?int}>, failed_units: ?list<string>, containers: ?list<array{name: string, state: string, health: ?string, restart_count: int}>}|null $report */
        $report = $server->last_report;

        if ($report === null) {
            return null;
        }

        $memoryTotalBytes = $report['memory']['total_bytes'];
        $memoryUsedBytes = max(0, $memoryTotalBytes - $report['memory']['available_bytes']);

        return [
            'cpu_percent' => (float) $report['cpu_percent'],
            'load' => array_map(floatval(...), $report['load']),
            'memory_used_percent' => $this->percent($memoryUsedBytes, $memoryTotalBytes),
            'filesystems' => array_map(fn (array $filesystem): array => [
                'mount' => $filesystem['mount'],
                'fstype' => $filesystem['fstype'],
                'unresponsive' => $filesystem['unresponsive'],
                'used_percent' => $filesystem['unresponsive'] ? null : $this->percent((int) $filesystem['used_bytes'], (int) $filesystem['used_bytes'] + (int) $filesystem['available_bytes']),
                'used_bytes' => $filesystem['unresponsive'] ? null : $filesystem['used_bytes'],
                'total_bytes' => $filesystem['unresponsive'] ? null : $filesystem['total_bytes'],
                'available_bytes' => $filesystem['unresponsive'] ? null : $filesystem['available_bytes'],
            ], $report['filesystems']),
            'failed_units' => $report['failed_units'],
            'containers' => $report['containers'],
        ];
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /**
     * `last_observed` goes out as an object, so an empty result stays `{}` rather than `[]`.
     *
     * @return array{id: int, uuid: string, type: string, type_label: string, label: string, params: array<string, mixed>, is_enabled: bool, last_status: ?string, last_status_label: ?string, last_message: ?string, last_observed: ?object, last_evaluated_at: ?string}
     */
    private function check(ServerCheck $serverCheck): array
    {
        return [
            'id' => $serverCheck->id,
            'uuid' => $serverCheck->uuid,
            'type' => $serverCheck->type->value,
            'type_label' => $serverCheck->type->label(),
            'label' => $serverCheck->label(),
            'params' => $serverCheck->params,
            'is_enabled' => $serverCheck->is_enabled,
            'last_status' => $serverCheck->last_status?->value,
            'last_status_label' => $serverCheck->last_status?->label(),
            'last_message' => $serverCheck->last_message,
            'last_observed' => $serverCheck->last_observed === null ? null : (object) $serverCheck->last_observed,
            'last_evaluated_at' => $serverCheck->last_evaluated_at?->toIso8601String(),
        ];
    }

    /**
     * Open incidents (critical first, newest first), then the 20 most recently resolved.
     *
     * @return list<array{id: int, key: string, severity: string, status: string, title: string, detail: ?string, opened_at: string, last_seen_at: string, escalated_at: ?string, resolved_at: ?string}>
     */
    private function incidents(Server $server): array
    {
        $open = $server->incidents()
            ->open()
            ->orderByRaw('severity = ? DESC', [IncidentSeverity::Critical->value])
            ->latest('opened_at')
            ->latest('id')
            ->get();

        $resolved = $server->incidents()
            ->where('status', IncidentStatus::Resolved->value)
            ->latest('resolved_at')
            ->latest('id')
            ->limit(20)
            ->get();

        return $open->concat($resolved)->map(static fn (ServerIncident $serverIncident): array => [
            'id' => $serverIncident->id,
            'key' => $serverIncident->key,
            'severity' => $serverIncident->severity->value,
            'status' => $serverIncident->status->value,
            'title' => $serverIncident->title,
            'detail' => $serverIncident->detail,
            'opened_at' => $serverIncident->opened_at->toIso8601String(),
            'last_seen_at' => $serverIncident->last_seen_at->toIso8601String(),
            'escalated_at' => $serverIncident->escalated_at?->toIso8601String(),
            'resolved_at' => $serverIncident->resolved_at?->toIso8601String(),
        ])->values()->all();
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
