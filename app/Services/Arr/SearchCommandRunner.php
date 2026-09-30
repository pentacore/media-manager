<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Enums\MediaSearchCommand;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Runs a `search_media` command against Sonarr/Radarr. Every search opts out
 * of the generic HTTP retry (`runCommand(..., withRetry: false)`) since a
 * targeted search is cheap to ask the member to retry, but a
 * MissingEpisodeSearch/CutoffUnmetEpisodeSearch/MissingMoviesSearch/
 * CutoffUnmetMoviesSearch sweeps the entire library — a lost response
 * multiplied by the generic retry's 3 attempts, and again by the queue job's
 * own retry budget, could fire it up to 9 times. For those library-wide
 * commands specifically, a connection loss or 5xx becomes a permanent
 * failure instead of retrying blind, mirroring how {@see ReleaseGrabber}
 * treats an unconfirmed grab.
 */
final readonly class SearchCommandRunner
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws SearchCommandFailed|RequestException|ConnectionException
     */
    public function run(ArrClient $arrClient, string $service, MediaSearchCommand $command, array $parameters): array
    {
        try {
            return $arrClient->runCommand($command->arrCommand(), $parameters, withRetry: false);
        } catch (ConnectionException $connectionException) {
            throw_unless($command->isLibraryWide(), $connectionException);

            throw SearchCommandFailed::unconfirmed($service);
        } catch (RequestException $requestException) {
            throw_unless($command->isLibraryWide() && $requestException->response->serverError(), $requestException);

            throw SearchCommandFailed::unconfirmed($service);
        }
    }
}
