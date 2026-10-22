<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Support\Str;

/**
 * Stores each reported check result on the server's own check with that uuid. Ids of other servers' checks and unknown ids are ignored.
 */
final readonly class CheckResultRecorder
{
    public function record(Server $server, AgentReport $agentReport): void
    {
        foreach ($agentReport->checks as $check) {
            // A builder update: no model events, so a result never bumps config_version, and updated_at keeps meaning "last edited".
            // `observed` is always an object; the (object) cast keeps an empty one `{}` instead of `[]`.
            ServerCheck::query()
                ->where('server_id', $server->id)
                ->where('uuid', $check['id'])
                ->toBase()
                ->update([
                    'last_status' => $check['status']->value,
                    'last_message' => $check['message'] === null ? null : Str::limit($check['message'], 500, ''),
                    'last_observed' => $check['observed'] === null ? null : json_encode((object) $check['observed'], JSON_THROW_ON_ERROR),
                    'last_evaluated_at' => $agentReport->receivedAt,
                ]);
        }
    }
}
