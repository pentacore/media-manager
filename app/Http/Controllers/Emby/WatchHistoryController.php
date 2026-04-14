<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\EmbyActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WatchHistoryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $builder = EmbyActivity::with('embyUserLink:id,emby_username,user_id')
            ->latest();

        if ($user !== null && $user->role === UserRole::Viewer) {
            $linkIds = $user->embyUserLinks()->pluck('id');
            $builder->whereIn('emby_user_link_id', $linkIds);
        }

        if ($request->filled('media_type')) {
            $builder->where('media_type', $request->string('media_type'));
        }

        $lengthAwarePaginator = $builder->paginate(25)->withQueryString();

        return Inertia::render('Emby/WatchHistory', [
            'activities' => [
                'data' => $lengthAwarePaginator->getCollection()->map(fn (EmbyActivity $embyActivity): array => [
                    'id' => $embyActivity->id,
                    'media_type' => $embyActivity->media_type,
                    'media_title' => $embyActivity->media_title,
                    'series_title' => $embyActivity->series_title,
                    'action' => $embyActivity->action,
                    'play_position' => $embyActivity->play_position,
                    'duration_ticks' => $embyActivity->duration_ticks,
                    'emby_username' => $embyActivity->embyUserLink?->emby_username,
                    'created_at' => $embyActivity->created_at?->toISOString(),
                ])->all(),
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
            ],
        ]);
    }
}
