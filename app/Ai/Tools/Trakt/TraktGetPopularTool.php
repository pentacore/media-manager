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

class TraktGetPopularTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Fetch the all-time most-popular titles on Trakt. Use for "evergreen" recommendations rather than what is hot right now.';
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

        return ['results' => new TraktClient()->getPopular($mediaType)];
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
