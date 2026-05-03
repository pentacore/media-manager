<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Emby\BackfillResult;
use App\Services\Emby\EmbyClient;
use App\Services\Emby\EmbyHistoryBackfiller;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('emby:backfill-history
    {--user= : Match against EmbyUserLink.emby_username, then User.email, then numeric User.id}
    {--dry-run : Map and report counts without writing}
    {--page-size=500 : Items per Emby page request}
    {--since= : Only items with LastPlayedDate on/after YYYY-MM-DD (server-side via MinDateLastSaved)}')]
#[Description('Backfill Emby watch history into emby_activities by querying the Emby REST API.')]
class BackfillEmbyHistory extends Command
{
    public function handle(): int
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            $this->error('No active Emby connection configured.');

            return self::FAILURE;
        }

        $userOption = $this->option('user');
        $userOption = is_string($userOption) && $userOption !== '' ? $userOption : null;

        $links = $this->resolveLinks($userOption);
        if (! $links instanceof Collection) {
            return self::FAILURE;
        }

        if ($links->isEmpty()) {
            $this->error($userOption !== null
                ? sprintf("No EmbyUserLink matched '%s'.", $userOption)
                : 'No EmbyUserLink rows found. Link a user before running backfill.');

            return self::FAILURE;
        }

        $pageSize = (int) $this->option('page-size');
        if ($pageSize <= 0) {
            $pageSize = 500;
        }

        $dryRun = (bool) $this->option('dry-run');

        $sinceOption = $this->option('since');
        $since = null;
        if (is_string($sinceOption) && $sinceOption !== '') {
            try {
                $since = CarbonImmutable::parse($sinceOption);
            } catch (Throwable) {
                $this->error(sprintf("Invalid --since value '%s'. Use YYYY-MM-DD.", $sinceOption));

                return self::FAILURE;
            }
        }

        $this->info(sprintf('Using Emby connection #%d (%s) at %s', $connection->id, $connection->name, $connection->url));
        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $embyHistoryBackfiller = new EmbyHistoryBackfiller(new EmbyClient($connection));
        $usersOk = 0;
        $usersFailed = 0;

        foreach ($links as $link) {
            $this->line(sprintf('→ %s (emby_user_id=%s)', $link->emby_username, $link->emby_user_id));

            try {
                $result = $embyHistoryBackfiller->backfillUser(
                    embyUserLink: $link,
                    pageSize: $pageSize,
                    since: $since,
                    dryRun: $dryRun,
                );
                $this->printSummary($result);
                $usersOk++;
            } catch (RequestException|ConnectionException $exception) {
                $usersFailed++;
                $this->warn(sprintf('  failed: %s', $exception->getMessage()));
                Log::warning('emby:backfill-history per-user failure', [
                    'emby_user_link_id' => $link->id,
                    'emby_user_id' => $link->emby_user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info(sprintf('Done. %d user(s) ok, %d failed.', $usersOk, $usersFailed));

        return $usersOk > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, EmbyUserLink>|null null indicates a hard failure (already reported).
     */
    private function resolveLinks(?string $userOption): ?Collection
    {
        if ($userOption === null) {
            return EmbyUserLink::query()->get();
        }

        $byUsername = EmbyUserLink::where('emby_username', $userOption)->get();
        if ($byUsername->isNotEmpty()) {
            return $byUsername;
        }

        $byEmail = User::where('email', $userOption)->first();
        if ($byEmail !== null) {
            return EmbyUserLink::where('user_id', $byEmail->id)->get();
        }

        if (ctype_digit($userOption)) {
            $byId = User::find((int) $userOption);
            if ($byId !== null) {
                return EmbyUserLink::where('user_id', $byId->id)->get();
            }
        }

        return new Collection;
    }

    private function printSummary(BackfillResult $backfillResult): void
    {
        $this->line(sprintf('  fetched=%d', $backfillResult->itemsFetched));
        $this->line(sprintf('  created=%d', $backfillResult->itemsCreated));
        $this->line(sprintf('  updated=%d', $backfillResult->itemsUpdated));
        $this->line(sprintf('  skipped=%d', $backfillResult->itemsSkipped));
    }
}
