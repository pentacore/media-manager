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

class TraktGetListTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Fetch items from a curated Trakt list by its numeric list_id. Useful for themed picks like "Best of A24" or "Dad-jokes movies".';
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
        $listId = (int) ($request->toArray()['list_id'] ?? 0);

        return ['results' => new TraktClient()->getList($listId)];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()
                ->description('Numeric Trakt list id (e.g. 12345 for a public curated list).')
                ->required(),
        ];
    }
}
