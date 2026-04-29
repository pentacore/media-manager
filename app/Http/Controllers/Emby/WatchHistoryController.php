<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmbyActivityResource;
use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WatchHistoryController extends Controller
{
    /** Allowed time-range buckets for ?since= (in days). */
    private const array RANGE_DAYS = [1, 7, 30, 90];

    public function index(Request $request): Response
    {
        $since = $this->resolveSince($request);

        $builder = $this->buildBuilder($request, $since)
            ->with('embyUserLink:id,emby_username,user_id');

        $lengthAwarePaginator = $builder->paginate(25)->withQueryString();

        return Inertia::render('Emby/WatchHistory', [
            'connection' => $this->resolveConnectionPayload(),
            'activities' => [
                'data' => EmbyActivityResource::collection($lengthAwarePaginator->getCollection())->toArray($request),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
            'filters' => [
                'media_type' => $request->string('media_type')->toString(),
                'since' => $since,
            ],
            'filterOptions' => [
                'rangeDays' => self::RANGE_DAYS,
            ],
        ]);
    }

    /**
     * @return array{url: string}|null
     */
    private function resolveConnectionPayload(): ?array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            return null;
        }

        return ['url' => rtrim($connection->url, '/')];
    }

    public function export(Request $request): StreamedResponse
    {
        $since = $this->resolveSince($request);

        $builder = $this->buildBuilder($request, $since)
            ->with('embyUserLink:id,emby_username,user_id');

        $filename = sprintf('watch-history-%dd-%s.csv', $since, now()->format('Ymd-His'));

        return new StreamedResponse(function () use ($builder): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'id', 'started_at', 'emby_user', 'media_type', 'media_title',
                'series_title', 'duration_seconds', 'play_position_seconds',
                'completion_pct', 'action',
            ],
                escape: '\\');

            $builder->lazyById(500)->each(function (EmbyActivity $embyActivity) use ($handle): void {
                $duration = $embyActivity->duration_ticks ?? 0;
                $position = $embyActivity->play_position ?? 0;
                $completion = $duration > 0 ? round(min(100, $position / $duration * 100), 1) : 0;

                fputcsv($handle, [
                    $embyActivity->id,
                    $embyActivity->created_at?->toIso8601String(),
                    $embyActivity->embyUserLink?->emby_username,
                    $embyActivity->media_type,
                    $embyActivity->media_title,
                    $embyActivity->series_title,
                    intdiv((int) $duration, 10_000_000),
                    intdiv((int) $position, 10_000_000),
                    $completion,
                    $embyActivity->action,
                ],
                    escape: '\\');
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return Builder<EmbyActivity>
     */
    private function buildBuilder(Request $request, int $since): Builder
    {
        $user = $request->user();

        $builder = EmbyActivity::query()->latest();

        if ($user !== null && $user->role === UserRole::Viewer) {
            $linkIds = $user->embyUserLinks()->pluck('id');
            $builder->whereIn('emby_user_link_id', $linkIds);
        }

        if ($request->filled('media_type')) {
            $builder->where('media_type', $request->string('media_type')->toString());
        }

        $builder->where('created_at', '>=', CarbonImmutable::now()->subDays($since));

        return $builder;
    }

    private function resolveSince(Request $request): int
    {
        $since = $request->integer('since', 7);

        return in_array($since, self::RANGE_DAYS, true) ? $since : 7;
    }
}
