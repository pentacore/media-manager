<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmbyActivityResource;
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
            ],
        ]);
    }
}
