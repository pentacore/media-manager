<?php

declare(strict_types=1);

namespace App\Ai\Tools\Emby;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Models\EmbyActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class WatchHistoryTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get recent Emby watch history (action=played) within the look-back window.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>}
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $sinceDays = max(1, min(365, (int) ($args['since_days'] ?? 30)));
        $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));

        $entries = EmbyActivity::with('embyUserLink:id,emby_username')
            ->where('action', 'played')
            ->where('created_at', '>=', now()->subDays($sinceDays))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (EmbyActivity $a): array => [
                'id' => $a->id,
                'media_type' => $a->media_type,
                'media_title' => $a->media_title,
                'series_title' => $a->series_title,
                'emby_username' => $a->embyUserLink?->emby_username,
                'created_at' => $a->created_at?->toISOString(),
            ])->all();

        return ['entries' => $entries];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since_days' => $schema->integer()
                ->description('Look-back window in days (1-365, default 30). Pass null for default.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max rows to return (1-100, default 50). Pass null for default.')
                ->required()
                ->nullable(),
        ];
    }
}
