<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Override;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use Illuminate\Console\Command;

class CheckServiceHealth extends Command
{
    #[Override]
    protected $signature = 'services:check-health';

    #[Override]
    protected $description = 'Ping every active service connection and update health_status.';

    public function handle(): int
    {
        $connections = ServiceConnection::where('is_active', true)->get();

        foreach ($connections as $connection) {
            new PingServiceHealth($connection)->handle();
        }

        $this->info(sprintf('Checked %d service(s).', $connections->count()));

        return self::SUCCESS;
    }
}
