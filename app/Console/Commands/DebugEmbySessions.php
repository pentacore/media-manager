<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

#[Signature('emby:debug-sessions {--raw : Dump the full JSON response} {--query-auth : Use api_key query param instead of X-Emby-Token header}')]
#[Description('Diagnose what Emby /Sessions is returning for the active Emby connection.')]
class DebugEmbySessions extends Command
{
    public function handle(): int
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            $this->error('No active Emby connection configured.');

            return self::FAILURE;
        }

        $this->info(sprintf('Using connection #%d (%s) at %s', $connection->id, $connection->name, $connection->url));

        $baseUrl = rtrim((string) $connection->url, '/');

        try {
            if ($this->option('query-auth')) {
                $response = Http::baseUrl($baseUrl)
                    ->timeout(10)
                    ->connectTimeout(3)
                    ->get('/Sessions', ['api_key' => $connection->api_key]);
            } else {
                $response = Http::baseUrl($baseUrl)
                    ->withHeaders(['X-Emby-Token' => $connection->api_key])
                    ->timeout(10)
                    ->connectTimeout(3)
                    ->get('/Sessions');
            }
        } catch (RequestException $requestException) {
            $this->error(sprintf('HTTP error (%d): %s', $requestException->response?->status() ?? 0, $requestException->getMessage()));

            return self::FAILURE;
        } catch (ConnectionException $connectionException) {
            $this->error('Connection error: '.$connectionException->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('HTTP status: %d', $response->status()));
        $this->line('Content-Type: '.($response->header('Content-Type') ?: '-'));

        $raw = $response->json();
        $this->line('Body type:    '.(is_array($raw) ? ('array['.count($raw).']') : get_debug_type($raw)));

        if (! $response->successful()) {
            $this->warn('Non-2xx response. Body (first 500 chars):');
            $this->line(substr($response->body(), 0, 500));

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! is_array($raw)) {
            $this->warn('/Sessions did not return an array. Re-run with --raw to see the full response.');
            $this->line('Body (first 500 chars): '.substr($response->body(), 0, 500));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Session summary:');
        foreach ($raw as $i => $session) {
            $client = $session['Client'] ?? '?';
            $user = $session['UserName'] ?? '-';
            $device = $session['DeviceName'] ?? '?';
            $nowPlaying = isset($session['NowPlayingItem'])
                ? sprintf('"%s"', $session['NowPlayingItem']['Name'] ?? '?')
                : '(idle)';
            $this->line(sprintf('  [%d] %s · %s · %s · %s', $i, $user, $client, $device, $nowPlaying));
        }

        $playing = array_filter($raw, static fn (array $s): bool => isset($s['NowPlayingItem']));
        $this->line('');
        $this->info(sprintf('%d total session(s), %d with NowPlayingItem.', count($raw), count($playing)));

        if ($raw !== [] && $playing === []) {
            $this->warn('All sessions are idle. If something IS playing, Emby may be returning stale sessions.');
            $this->line('Try adding a query param: --raw shows full payload for inspection.');
        }

        return self::SUCCESS;
    }
}
