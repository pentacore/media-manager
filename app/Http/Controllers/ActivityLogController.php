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

    /** Special ?since= value: cutoff snaps to start of the local day. */
    private const string TODAY = 'today';

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
                'todayValue' => self::TODAY,
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

            $builder->lazyById(500)->each(function (ActivityLog $activityLog) use ($handle): void {
                fwrite($handle, json_encode([
                    'id' => $activityLog->id,
                    'created_at' => $activityLog->created_at?->toIso8601String(),
                    'user' => $activityLog->user?->name,
                    'service' => $activityLog->serviceConnection?->name,
                    'service_type' => $activityLog->serviceConnection?->type->value,
                    'action' => $activityLog->action,
                    'description' => $activityLog->description,
                    'subject_type' => $activityLog->subject_type,
                    'subject_id' => $activityLog->subject_id,
                    'metadata' => $activityLog->metadata,
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
    private function buildBuilder(string $action, int $serviceId, int|string $since): Builder
    {
        $builder = ActivityLog::query()->latest();

        if ($action !== '') {
            $builder->where('action', $action);
        }

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        $builder->where('created_at', '>=', $this->cutoffFor($since));

        return $builder;
    }

    /**
     * "today" snaps to the local midnight; numeric values walk back N hours
     * from now. Mixed return type keeps the wire format honest — frontend
     * sends `since=today` literally instead of pretending it's a number.
     */
    private function resolveSince(Request $request): int|string
    {
        $raw = $request->string('since', '24')->toString();

        if ($raw === self::TODAY) {
            return self::TODAY;
        }

        $hours = (int) $raw;

        return in_array($hours, self::RANGE_HOURS, true) ? $hours : 24;
    }

    private function cutoffFor(int|string $since): CarbonImmutable
    {
        return $since === self::TODAY
            ? CarbonImmutable::today()
            : CarbonImmutable::now()->subHours($since);
    }
}
