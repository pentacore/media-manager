<?php

declare(strict_types=1);

namespace App\Ai\Tools\Seerr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class ApproveRequestTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Approve a pending Seerr media request by request id. ALWAYS queues an ActionRequest (auto-executed or pending approval per the admin rules). Identify the seerr_request_id first via ListPendingRequestsTool — never guess.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{type: string, target_service: string, payload: array<string, mixed>, fallback_title: ?string}
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();

        return [
            'type' => 'approve_seerr_request',
            'target_service' => 'seerr',
            'payload' => [
                'seerr_request_id' => (int) ($args['seerr_request_id'] ?? 0),
            ],
            'fallback_title' => is_string($args['title'] ?? null) ? $args['title'] : null,
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seerr_request_id' => $schema->integer()
                ->description('Seerr request id to approve (use ListPendingRequestsTool to find).')
                ->required(),
            'title' => $schema->string()
                ->description('Title of the requested media as shown to the user. Only displayed if the server cannot look the request up; such requests always wait for approval.')
                ->required()
                ->nullable(),
        ];
    }
}
