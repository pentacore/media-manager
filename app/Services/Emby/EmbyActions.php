<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class EmbyActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'emby_library_scan' => $this->libraryScan(),
            default => throw new InvalidArgumentException(sprintf('EmbyActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryScan(): array
    {
        $embyClient = new EmbyClient(ServiceConnection::resolveActive(ServiceType::Emby));
        $embyClient->refreshLibrary();

        return ['triggered' => true];
    }
}
