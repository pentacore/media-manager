<?php

declare(strict_types=1);

namespace App\Ai\Tools\Trakt;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Services\Trakt\TraktClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class TraktGetTrendingTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Fetch what is trending on Trakt right now (most watchers in the last 24h). Use to suggest "what is everyone watching this weekend?".';
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
        $mediaType = (string) ($request->toArray()['media_type'] ?? '');

        return ['results' => new TraktClient()->getTrending($mediaType)];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'media_type' => $schema->string()
                ->description('Either "movie" or "tv".')
                ->required(),
        ];
    }
}
