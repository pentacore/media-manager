<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\JsonSchema\Types\Type;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryActivityTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Query recent Emby playback activity and system activity logs. '
            .'Use scope="emby" for playback history or scope="system" for admin/webhook activity. '
            .'Optional filters: media_type (movie|episode), since_days (integer, default 30), limit (max 50).';
    }

    public function handle(Request $request): Stringable|string
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
                ->map(fn (ActivityLog $activityLog): array => [
                    'id' => $activityLog->id,
                    'action' => $activityLog->action,
                    'description' => $activityLog->description,
                    'user' => $activityLog->user?->name,
                    'service' => $activityLog->serviceConnection?->name,
                    'created_at' => $activityLog->created_at?->toISOString(),
                ])->all();

            return json_encode(['scope' => 'system', 'entries' => $logs]);
        }

        $builder = EmbyActivity::with('embyUserLink:id,emby_username')
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit($limit);

        if (is_string($mediaType) && in_array($mediaType, ['movie', 'episode'], true)) {
            $builder->where('media_type', $mediaType);
        }

        $activities = $builder->get()->map(fn (EmbyActivity $embyActivity): array => [
            'id' => $embyActivity->id,
            'media_type' => $embyActivity->media_type,
            'media_title' => $embyActivity->media_title,
            'series_title' => $embyActivity->series_title,
            'action' => $embyActivity->action,
            'emby_username' => $embyActivity->embyUserLink?->emby_username,
            'created_at' => $embyActivity->created_at?->toISOString(),
        ])->all();

        return json_encode(['scope' => 'emby', 'entries' => $activities]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->description('Either "emby" (playback) or "system" (webhooks/admin). Default: emby.'),
            'media_type' => $schema->string()->description('Filter by media_type for emby scope: "movie" or "episode".'),
            'since_days' => $schema->integer()->description('Look-back window in days (1-365, default 30).'),
            'limit' => $schema->integer()->description('Max rows to return (1-50, default 25).'),
        ];
    }
}
