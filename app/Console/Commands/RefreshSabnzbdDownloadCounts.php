<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Console\Command;
use Override;

class RefreshSabnzbdDownloadCounts extends Command
{
    #[Override]
    protected $signature = 'sabnzbd:refresh-download-counts';

    #[Override]
    protected $description = 'Recompute the SABnzbd queued + still-in-history counts and broadcast them to the sidebar.';

    public function handle(SabnzbdDownloadCounter $sabnzbdDownloadCounter): void
    {
        $counts = $sabnzbdDownloadCounter->recompute();

        $this->info(sprintf('SAB downloads — queued: %d · completed: %d', $counts['queued'], $counts['completed']));
    }
}
