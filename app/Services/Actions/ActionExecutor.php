<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Models\ActionRequest;

interface ActionExecutor
{
    /**
     * Execute the action. Return an array that will be stored as the ActionRequest result.
     *
     * Throw any exception to mark the ActionRequest as Failed (exception message captured in result).
     *
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array;
}
