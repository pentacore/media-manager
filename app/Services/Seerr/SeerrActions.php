<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class SeerrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'cleanup_seerr_request' => $this->cleanupRequest($actionRequest),
            default => throw new InvalidArgumentException(sprintf('SeerrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupRequest(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $requestId = (int) ($payload['seerr_request_id'] ?? 0);

        throw_if($requestId <= 0, InvalidArgumentException::class, 'seerr_request_id is required');

        $seerrClient = new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
        $seerrClient->deleteRequest($requestId);

        return [
            'seerr_request_id' => $requestId,
        ];
    }
}
