<?php

declare(strict_types=1);

namespace App\Concerns;

trait PinnedConnectionValidationRules
{
    /**
     * The connection the page, row or tab was rendered from. Media, queue,
     * history and download ids overlap between instances of one service, so
     * a write on them is pinned to that connection (resolved strictly by the
     * controller) and the pin is mandatory.
     *
     * @return array<string, list<string>>
     */
    protected function pinnedConnectionRules(): array
    {
        return [
            'service_connection_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
