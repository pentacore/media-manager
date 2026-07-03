<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceConnection;
use App\Support\ServiceCheckBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Ping every active service connection and update health_status.')]
#[Signature('services:check-health')]
class CheckServiceHealth extends Command
{
    public function handle(): int
    {
        $connections = ServiceConnection::where('is_active', true)->get();

        if ($connections->isEmpty()) {
            $this->info('No active service connections to check.');

            return self::SUCCESS;
        }

        $batch = ServiceCheckBatch::dispatchHealth($connections);

        $this->info(sprintf('Dispatched batch %s for %d service(s).', $batch->id, $connections->count()));

        return self::SUCCESS;
    }
}
