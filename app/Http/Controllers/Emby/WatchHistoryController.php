<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Enums\TimeWindow;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emby\SetPlayedStateRequest;
use App\Http\Resources\EmbyActivityResource;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WatchHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $timeWindow = TimeWindow::fromRequest($request->string('since')->value() ?: null);

        $builder = $this->buildBuilder($request, $timeWindow)
            ->with('embyUserLink:id,emby_username,user_id,emby_user_id');

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
            'totals' => $this->totalsFor($request, $timeWindow),
            'filters' => [
                'media_type' => $request->string('media_type')->toString(),
                'since' => $timeWindow->value,
            ],
            'filterOptions' => [
                'windows' => TimeWindow::options(),
            ],
        ]);
    }

    /**
     * @return array{url: string}|null
     */
    private function resolveConnectionPayload(): ?array
    {
        $connection = ServiceConnection::findActive(ServiceType::Emby);

        return $connection instanceof ServiceConnection ? ['url' => $connection->linkUrl()] : null;
    }

    /**
     * Mark a watched item played or unplayed in Emby for the row's own Emby
     * user. The ownership check is the whole authorization: admins may
     * change any row, everyone else only their own.
     */
    public function togglePlayed(SetPlayedStateRequest $setPlayedStateRequest, EmbyActivity $embyActivity): RedirectResponse
    {
        $user = $setPlayedStateRequest->user();
        $embyActivity->loadMissing('embyUserLink:id,user_id,emby_user_id,emby_username');

        abort_unless($embyActivity->playedStateEditableBy($user), 403);

        $embyUserId = $embyActivity->embyUserLink->emby_user_id;
        $embyItemId = $embyActivity->emby_item_id;

        // Both ids travel in the Emby URL path; anything but an Emby id is refused.
        abort_unless(preg_match('/^[A-Za-z0-9-]{1,64}$/', $embyUserId) === 1 && preg_match('/^[A-Za-z0-9-]{1,64}$/', $embyItemId) === 1, 422);

        $validated = $setPlayedStateRequest->validated();
        $played = (bool) $validated['played'];

        $connection = ServiceConnection::findActive(ServiceType::Emby);

        if (! $connection instanceof ServiceConnection) {
            return $this->noActiveConnectionRedirect(ServiceType::Emby, back());
        }

        $embyClient = new EmbyClient($connection);

        try {
            $played
                ? $embyClient->markItemPlayed($embyUserId, $embyItemId)
                : $embyClient->markItemUnplayed($embyUserId, $embyItemId);
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Emby is unreachable right now.')]);

            return back();
        } catch (RequestException $requestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $requestException->response->serverError()
                ? __('Emby is unreachable right now.')
                : __('Emby refused the change.')]);

            return back();
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'action' => $played ? 'emby.item.marked_played' : 'emby.item.marked_unplayed',
            'subject_type' => $embyActivity->getMorphClass(),
            'subject_id' => $embyActivity->id,
            'description' => sprintf('Marked "%s" as %s for %s.', $embyActivity->media_title, $played ? 'played' : 'unplayed', $embyActivity->embyUserLink->emby_username),
            'metadata' => ['emby_item_id' => $embyItemId, 'played' => $played],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $played ? __('Marked as played.') : __('Marked as unplayed.')]);

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $timeWindow = TimeWindow::fromRequest($request->string('since')->value() ?: null);

        $builder = $this->buildBuilder($request, $timeWindow)
            ->with('embyUserLink:id,emby_username,user_id');

        $filename = sprintf('watch-history-%s-%s.csv', $timeWindow->value, now()->format('Ymd-His'));

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
    private function buildBuilder(Request $request, TimeWindow $timeWindow): Builder
    {
        return $this->applyFilters(EmbyActivity::query(), $request, $timeWindow)->latest();
    }

    /**
     * @param  Builder<EmbyActivity>  $builder
     * @return Builder<EmbyActivity>
     */
    private function applyFilters(Builder $builder, Request $request, TimeWindow $timeWindow): Builder
    {
        $user = $request->user();

        if ($user !== null && $user->role === UserRole::Viewer) {
            $linkIds = $user->embyUserLinks()->pluck('id');
            $builder->whereIn('emby_user_link_id', $linkIds);
        }

        if ($request->filled('media_type')) {
            $builder->where('media_type', $request->string('media_type')->toString());
        }

        $cutoff = $timeWindow->cutoff();

        if ($cutoff instanceof CarbonImmutable) {
            $builder->where('created_at', '>=', $cutoff);
        }

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
    private function totalsFor(Request $request, TimeWindow $timeWindow): array
    {
        $aggregate = $this->applyFilters(EmbyActivity::query(), $request, $timeWindow)
            ->selectRaw('COALESCE(SUM(play_position), 0) AS total_ticks')
            ->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('SUM(CASE WHEN duration_ticks > 0 AND play_position * 10 >= duration_ticks * 9 THEN 1 ELSE 0 END) AS completed_sessions')
            ->first();

        $topGroup = $this->applyFilters(EmbyActivity::query(), $request, $timeWindow)
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
}
