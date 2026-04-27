<?php

declare(strict_types=1);

namespace App\Ai\Tools\System;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryActivityTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Query recent Emby playback activity and system activity logs. '
            .'Use scope="emby" for playback history or scope="system" for admin/webhook activity. '
            .'Optional filters: media_type (movie|episode), since_days (integer, default 30), limit (max 50).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $scope = (string) ($args['scope'] ?? 'emby');
        $sinceDays = max(1, min(365, (int) ($args['since_days'] ?? 30)));
        $limit = max(1, min(50, (int) ($args['limit'] ?? 25)));
        $mediaType = $args['media_type'] ?? null;

        $since = now()->subDays($sinceDays);

        if ($scope === 'system') {
            $logs = ActivityLog::with('user:id,name', 'serviceConnection:id,name,type')
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user' => $log->user?->name,
                    'service' => $log->serviceConnection?->name,
                    'created_at' => $log->created_at?->toISOString(),
                ])->all();

            return ['scope' => 'system', 'entries' => $logs];
        }

        $builder = EmbyActivity::with('embyUserLink:id,emby_username')
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit($limit);

        if (is_string($mediaType) && in_array($mediaType, ['movie', 'episode'], true)) {
            $builder->where('media_type', $mediaType);
        }

        $activities = $builder->get()->map(fn (EmbyActivity $a): array => [
            'id' => $a->id,
            'media_type' => $a->media_type,
            'media_title' => $a->media_title,
            'series_title' => $a->series_title,
            'action' => $a->action,
            'emby_username' => $a->embyUserLink?->emby_username,
            'created_at' => $a->created_at?->toISOString(),
        ])->all();

        return ['scope' => 'emby', 'entries' => $activities];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()
                ->description('Either "emby" (playback) or "system" (webhooks/admin). Default: emby.')
                ->required()
                ->nullable(),
            'media_type' => $schema->string()
                ->description('Filter by media_type for emby scope: "movie" or "episode". Pass null for no filter.')
                ->required()
                ->nullable(),
            'since_days' => $schema->integer()
                ->description('Look-back window in days (1-365, default 30). Pass null for default.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max rows to return (1-50, default 25). Pass null for default.')
                ->required()
                ->nullable(),
        ];
    }
}
