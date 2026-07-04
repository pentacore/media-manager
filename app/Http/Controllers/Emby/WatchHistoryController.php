<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmbyActivityResource;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WatchHistoryController extends Controller
{
    /** Allowed time-range buckets for ?since= (in days). */
    private const array RANGE_DAYS = [1, 7, 30, 90];

    /** Special ?since= value: cutoff snaps to start of the local day. */
    private const string TODAY = 'today';

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
            'totals' => $this->totalsFor($request, $since),
            'filters' => [
                'media_type' => $request->string('media_type')->toString(),
                'since' => $since,
            ],
            'filterOptions' => [
                'rangeDays' => self::RANGE_DAYS,
                'todayValue' => self::TODAY,
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

        $sinceLabel = $since === self::TODAY ? 'today' : sprintf('%dd', $since);
        $filename = sprintf('watch-history-%s-%s.csv', $sinceLabel, now()->format('Ymd-His'));

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
    private function buildBuilder(Request $request, int|string $since): Builder
    {
        return $this->applyFilters(EmbyActivity::query(), $request, $since)->latest();
    }

    /**
     * @param  Builder<EmbyActivity>  $builder
     * @return Builder<EmbyActivity>
     */
    private function applyFilters(Builder $builder, Request $request, int|string $since): Builder
    {
        $user = $request->user();

        if ($user !== null && $user->role === UserRole::Viewer) {
            $linkIds = $user->embyUserLinks()->pluck('id');
            $builder->whereIn('emby_user_link_id', $linkIds);
        }

        if ($request->filled('media_type')) {
            $builder->where('media_type', $request->string('media_type')->toString());
        }

        $builder->where('created_at', '>=', $this->cutoffFor($since));

        return $builder;
    }

    /**
     * @return array{
     *     total_ticks: int,
     *     sessions: int,
     *     completed_sessions: int,
     *     top_user: array{name: string, ticks: int, sessions: int}|null,
     * }
     */
    private function totalsFor(Request $request, int|string $since): array
    {
        $aggregate = $this->applyFilters(EmbyActivity::query(), $request, $since)
            ->selectRaw('COALESCE(SUM(play_position), 0) AS total_ticks')
            ->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('SUM(CASE WHEN duration_ticks > 0 AND play_position * 10 >= duration_ticks * 9 THEN 1 ELSE 0 END) AS completed_sessions')
            ->first();

        $topGroup = $this->applyFilters(EmbyActivity::query(), $request, $since)
            ->select('emby_user_link_id', DB::raw('SUM(play_position) AS ticks'), DB::raw('COUNT(*) AS sessions'))
            ->whereNotNull('emby_user_link_id')
            ->groupBy('emby_user_link_id')
            ->orderByDesc('ticks')
            ->first();

        $topUser = null;

        if ($topGroup !== null) {
            $link = EmbyUserLink::query()->find($topGroup->emby_user_link_id);

            if ($link !== null) {
                $topUser = [
                    'name' => $link->emby_username,
                    'ticks' => (int) $topGroup->ticks,
                    'sessions' => (int) $topGroup->sessions,
                ];
            }
        }

        return [
            'total_ticks' => (int) ($aggregate?->total_ticks ?? 0),
            'sessions' => (int) ($aggregate?->sessions ?? 0),
            'completed_sessions' => (int) ($aggregate?->completed_sessions ?? 0),
            'top_user' => $topUser,
        ];
    }

    private function resolveSince(Request $request): int|string
    {
        $raw = $request->string('since', '7')->toString();

        if ($raw === self::TODAY) {
            return self::TODAY;
        }

        $days = (int) $raw;

        return in_array($days, self::RANGE_DAYS, true) ? $days : 7;
    }

    private function cutoffFor(int|string $since): CarbonImmutable
    {
        return $since === self::TODAY
            ? CarbonImmutable::today()
            : CarbonImmutable::now()->subDays($since);
    }
}
