<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Jobs\ReconcileBazarrConnection;
use App\Models\ServiceConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;

#[Signature('bazarr:reconcile {--connection= : Reconcile one Bazarr connection id}')]
#[Description('Discover missing subtitles and queue bounded Bazarr case reconciliation.')]
final class ReconcileBazarrSubtitles extends Command
{
    public function handle(): int
    {
        $connectionId = $this->option('connection');

        ServiceConnection::query()
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->when(
                $connectionId !== null,
                fn (Builder $builder): Builder => $builder->whereKey((int) $connectionId),
            )
            ->orderBy('id')
            ->each(fn (ServiceConnection $serviceConnection): PendingDispatch => dispatch(new ReconcileBazarrConnection($serviceConnection->id)));

        return self::SUCCESS;
    }
}
