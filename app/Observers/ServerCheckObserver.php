<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerCheck;

/**
 * Bumps servers.config_version whenever a check's agent-facing definition changes, so the next report tells the agent to refetch its config.
 * Deliberately synchronous, not after-commit: the bump must commit with the check write itself.
 */
class ServerCheckObserver
{
    public function created(ServerCheck $serverCheck): void
    {
        $this->bump($serverCheck);
    }

    public function updated(ServerCheck $serverCheck): void
    {
        if ($serverCheck->wasChanged(['type', 'params', 'is_enabled'])) {
            $this->bump($serverCheck);
        }
    }

    public function deleted(ServerCheck $serverCheck): void
    {
        $this->bump($serverCheck);
    }

    private function bump(ServerCheck $serverCheck): void
    {
        Server::query()->whereKey($serverCheck->server_id)->increment('config_version');
    }
}
