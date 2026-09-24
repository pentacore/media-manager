<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

#[Signature('demo:fake-actions
    {--delay=2 : Seconds between actions so the UI has time to render}
    {--count=4 : How many fake actions to create across various states}')]
#[Description('Create fake ActionRequests in various states to demo the realtime Actions page.')]
class DemoFakeActions extends Command
{
    private bool $reverbWarned = false;

    public function handle(): int
    {
        $delaySeconds = max(0, (int) $this->option('delay'));
        $count = max(1, (int) $this->option('count'));

        $scenarios = collect($this->scenarios())->take($count);

        foreach ($scenarios as $scenario) {
            $this->createAction($scenario);

            if ($delaySeconds > 0 && $scenario !== $scenarios->last()) {
                Sleep::sleep($delaySeconds);
            }
        }

        $this->info('Done. Watch the Action Requests page (and per-user toast notifications).');

        return self::SUCCESS;
    }

    /**
     * @param  array{type: string, source: string, target: string, requires_approval: bool, status: ActionRequestStatus, payload: array<string, mixed>, title: string, description: string, details?: list<array{label: string, value: string}>, result?: array<string, mixed>}  $scenario
     */
    private function createAction(array $scenario): void
    {
        $action = ActionRequest::create([
            'webhook_event_id' => null,
            'type' => $scenario['type'],
            'source_service' => $scenario['source'],
            'target_service' => $scenario['target'],
            'status' => $scenario['status'],
            'requires_approval' => $scenario['requires_approval'],
            'approved_by' => null,
            'title' => $scenario['title'],
            'description' => $scenario['description'],
            'details' => $scenario['details'] ?? [],
            'payload' => $scenario['payload'],
            'result' => $scenario['result'] ?? null,
        ]);

        try {
            event(new ActionRequestCreated($action));

            if ($scenario['status'] !== ActionRequestStatus::Pending) {
                event(new ActionRequestStatusChanged($action));
            }
        } catch (BroadcastException $e) {
            $this->warnReverbOffline($e);
        } catch (Throwable $e) {
            $this->line(sprintf('  [%s → %s] %s — failed: %s',
                $scenario['source'], $scenario['target'], $scenario['type'], $e->getMessage()));

            return;
        }

        $this->line(sprintf('  [%s] %s → %s (%s)',
            $scenario['status']->value, $scenario['source'], $scenario['target'], $scenario['type']));
    }

    private function warnReverbOffline(BroadcastException $broadcastException): void
    {
        if ($this->reverbWarned) {
            return;
        }

        $this->reverbWarned = true;
        $this->newLine();
        $this->warn('Broadcast failed — Reverb is not reachable.');
        $this->warn('Start it with `vendor/bin/sail composer run dev` (boots app, queue, reverb, vite).');
        $this->line(sprintf('  underlying error: %s', $broadcastException->getMessage()));
        $this->newLine();
    }

    /**
     * @return list<array{type: string, source: string, target: string, requires_approval: bool, status: ActionRequestStatus, payload: array<string, mixed>, title: string, description: string, details?: list<array{label: string, value: string}>, result?: array<string, mixed>}>
     */
    private function scenarios(): array
    {
        return [
            [
                'type' => 'delete_series',
                'source' => 'emby',
                'target' => 'sonarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Pending,
                'payload' => ['sonarr_series_id' => 42, 'delete_files' => true],
                'title' => 'Delete series "Severance (2022)"',
                'description' => 'Emby reported "Severance" was removed from the library. Sonarr will delete the series and its files from disk.',
                'details' => [
                    ['label' => 'Delete files', 'value' => 'Yes'],
                ],
            ],
            [
                'type' => 'delete_movie',
                'source' => 'emby',
                'target' => 'radarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Pending,
                'payload' => ['radarr_movie_id' => 200, 'delete_files' => true],
                'title' => 'Delete movie "Dune: Part Two (2024)"',
                'description' => 'Emby reported "Dune: Part Two" was removed from the library. Radarr will delete the movie and its files from disk.',
                'details' => [
                    ['label' => 'Delete files', 'value' => 'Yes'],
                ],
            ],
            [
                'type' => 'emby_library_scan',
                'source' => 'sonarr',
                'target' => 'emby',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Completed,
                'payload' => ['trigger' => 'sonarr_download', 'series_title' => 'Demo Show'],
                'result' => ['success' => true, 'reason' => 'scan_dispatched'],
                'title' => 'Scan the Emby library',
                'description' => 'Sonarr downloaded a new episode of "Demo Show". Emby server "Demo Server" will rescan its libraries to pick up changes.',
                'details' => [
                    ['label' => 'Trigger', 'value' => 'Sonarr download'],
                ],
            ],
            [
                'type' => 'cleanup_seerr_request',
                'source' => 'emby',
                'target' => 'seerr',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Failed,
                'payload' => ['request_id' => 5099],
                'result' => ['success' => false, 'reason' => 'execution_failed'],
                'title' => 'Clean up Seerr request for "Demo Movie"',
                'description' => 'Emby reported "Demo Movie" was removed from the library. Seerr will delete the request.',
                'details' => [
                    ['label' => 'Request ID', 'value' => '5099'],
                ],
            ],
            [
                'type' => 'delete_series',
                'source' => 'emby',
                'target' => 'sonarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Rejected,
                'payload' => ['sonarr_series_id' => 99, 'delete_files' => false],
                'title' => 'Delete series "Industry (2020)"',
                'description' => 'Emby reported "Industry" was removed from the library. Sonarr will remove the series but keep its files on disk.',
                'details' => [
                    ['label' => 'Delete files', 'value' => 'No'],
                ],
            ],
            [
                'type' => 'emby_library_scan',
                'source' => 'seerr',
                'target' => 'emby',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Executing,
                'payload' => ['trigger' => 'seerr_media_available', 'subject' => 'Demo Movie'],
                'title' => 'Scan the Emby library',
                'description' => 'Seerr reported "Demo Movie" is now available. Emby server "Demo Server" will rescan its libraries to pick up changes.',
                'details' => [
                    ['label' => 'Trigger', 'value' => 'Seerr media available'],
                ],
            ],
        ];
    }
}
