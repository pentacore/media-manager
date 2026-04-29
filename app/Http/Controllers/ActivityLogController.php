<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * Allowed time-range buckets for ?since= (in hours). Anything else is
     * coerced back to "last 24h" so the filter input stays bounded.
     */
    private const array RANGE_HOURS = [1, 6, 24, 72, 168, 720];

    public function index(Request $request): Response
    {
        $action = $request->string('action')->toString();
        $serviceId = $request->integer('service_id');
        $since = $this->resolveSince($request);

        $lengthAwarePaginator = $this->buildBuilder($action, $serviceId, $since)
            ->with(['user:id,name', 'serviceConnection:id,name,type'])
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('ActivityLog', [
            'logs' => [
                'data' => ActivityLogResource::collection($lengthAwarePaginator->getCollection())->toArray($request),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'filters' => [
                'action' => $action,
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'since' => $since,
            ],
            'filterOptions' => [
                'actions' => ActivityLog::query()
                    ->select('action')
                    ->distinct()
                    ->orderBy('action')
                    ->pluck('action')
                    ->all(),
                'services' => ServiceConnection::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'type'])
                    ->map(fn (ServiceConnection $serviceConnection): array => [
                        'id' => $serviceConnection->id,
                        'name' => $serviceConnection->name,
                        'type' => $serviceConnection->type->value,
                    ])
                    ->all(),
                'rangeHours' => self::RANGE_HOURS,
            ],
        ]);
    }

    /**
     * Streams the filtered slice as newline-delimited JSON so it can be
     * piped into jq, grep, or any log-shipping tool. Honours the same
     * filters as index() so what you see is what you export.
     */
    public function export(Request $request): StreamedResponse
    {
        $action = $request->string('action')->toString();
        $serviceId = $request->integer('service_id');
        $since = $this->resolveSince($request);

        $builder = $this->buildBuilder($action, $serviceId, $since)
            ->with(['user:id,name', 'serviceConnection:id,name,type']);

        $filename = sprintf('activity-log-%s.ndjson', now()->format('Ymd-His'));

        return new StreamedResponse(function () use ($builder): void {
            $handle = fopen('php://output', 'wb');

            $builder->lazyById(500)->each(function (ActivityLog $log) use ($handle): void {
                fwrite($handle, json_encode([
                    'id' => $log->id,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'user' => $log->user?->name,
                    'service' => $log->serviceConnection?->name,
                    'service_type' => $log->serviceConnection?->type->value,
                    'action' => $log->action,
                    'description' => $log->description,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'metadata' => $log->metadata,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function buildBuilder(string $action, int $serviceId, int $since): Builder
    {
        $builder = ActivityLog::query()->latest();

        if ($action !== '') {
            $builder->where('action', $action);
        }

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        $builder->where('created_at', '>=', CarbonImmutable::now()->subHours($since));

        return $builder;
    }

    private function resolveSince(Request $request): int
    {
        $since = $request->integer('since', 24);

        return in_array($since, self::RANGE_HOURS, true) ? $since : 24;
    }
}
