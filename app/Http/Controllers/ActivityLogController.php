<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $action = $request->string('action')->toString();
        $serviceId = $request->integer('service_id');

        $builder = ActivityLog::with([
            'user:id,name',
            'serviceConnection:id,name,type',
        ])->latest();

        if ($action !== '') {
            $builder->where('action', $action);
        }

        if ($serviceId > 0) {
            $builder->where('service_connection_id', $serviceId);
        }

        $paginator = $builder->paginate(50)->withQueryString();

        return Inertia::render('ActivityLog', [
            'logs' => [
                'data' => ActivityLogResource::collection($paginator->getCollection())->toArray($request),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
            'filters' => [
                'action' => $action,
                'service_id' => $serviceId > 0 ? $serviceId : null,
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
            ],
        ]);
    }
}
