<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatTemplateResource;
use App\Models\ChatTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatTemplateOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $templates = ChatTemplate::query()
            ->whereBelongsTo($user)
            ->orderByDesc('pinned')
            ->orderByRaw('last_used_at desc nulls last')
            ->orderBy('name')
            ->get();

        return response()->json([
            'templates' => ChatTemplateResource::collection($templates)->toArray($request),
        ]);
    }
}
