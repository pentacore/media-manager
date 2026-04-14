<?php

declare(strict_types=1);

namespace App\Services\Jellyseerr;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class JellyseerrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'cleanup_jellyseerr_request' => $this->cleanupRequest($actionRequest),
            default => throw new InvalidArgumentException(sprintf('JellyseerrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupRequest(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $requestId = (int) ($payload['jellyseerr_request_id'] ?? 0);

        throw_if($requestId <= 0, InvalidArgumentException::class, 'jellyseerr_request_id is required');

        $jellyseerrClient = new JellyseerrClient(ServiceConnection::resolveActive(ServiceType::Jellyseerr));
        $jellyseerrClient->deleteRequest($requestId);

        return [
            'jellyseerr_request_id' => $requestId,
        ];
    }
}
