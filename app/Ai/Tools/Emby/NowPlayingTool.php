<?php

declare(strict_types=1);

namespace App\Ai\Tools\Emby;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Models\EmbyActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class NowPlayingTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get the currently-playing Emby sessions (active in the last ~10 minutes).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{sessions: array<int, array<string, mixed>>}
     */
    protected function execute(Request $request): array
    {
        $sessions = EmbyActivity::with('embyUserLink:id,emby_username')
            ->where('action', 'played')
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (EmbyActivity $a): array => [
                'id' => $a->id,
                'media_type' => $a->media_type,
                'media_title' => $a->media_title,
                'series_title' => $a->series_title,
                'emby_username' => $a->embyUserLink?->emby_username,
                'updated_at' => $a->updated_at?->toISOString(),
            ])->all();

        return ['sessions' => $sessions];
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
