<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActionRequestStatus;
use App\Enums\HealthStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\ServiceMetric;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Synthetic timeline data so a fresh local install lands on a populated
 * dashboard, action queue, activity log, watch history, and service-
 * health strip. Idempotent: bails out if the demo signature is already
 * present.
 */
class DemoActivitySeeder extends Seeder
{
    private const string DEMO_TAG = 'demo-seed';

    public function run(): void
    {
        if (ActivityLog::where('description', 'like', '['.self::DEMO_TAG.']%')->exists()) {
            return;
        }

        $admin = User::query()->where('role', 'admin')->first()
            ?? User::factory()->admin()->create([
                'name' => 'Demo Admin',
                'email' => 'demo-admin@example.com',
            ]);

        /** @var Collection<string, ServiceConnection> $services */
        $services = ServiceConnection::query()->get()
            ->keyBy(fn (ServiceConnection $c): string => $c->type->value);

        $this->seedWebhookEvents($services);
        $this->seedActionRequests($services);
        $this->seedEmbyActivity($admin, $services);
        $this->seedActivityLog($admin, $services);
        $this->seedServiceMetrics($services);
    }

    /**
     * Hourly webhook bursts across the last 24h, weighted toward the
     * busier services so the Webhooks card sparkline has shape.
     *
     * @param  Collection<string, ServiceConnection>  $services
     */
    private function seedWebhookEvents(Collection $services): void
    {
        $now = CarbonImmutable::now();
        $weights = [
            'sonarr' => 6,
            'radarr' => 4,
            'emby' => 5,
            'seerr' => 2,
            'prowlarr' => 1,
        ];

        for ($hour = 0; $hour < 24; $hour++) {
            foreach ($weights as $type => $base) {
                $service = $services->get($type);
                if ($service === null) {
                    continue;
                }

                $count = $base + random_int(0, 3);
                for ($i = 0; $i < $count; $i++) {
                    $when = $now->subHours($hour)->subMinutes(random_int(0, 59));
                    WebhookEvent::create([
                        'service_connection_id' => $service->id,
                        'event_type' => fake()->randomElement(['Download', 'Grab', 'Test', 'Health', 'MediaApproved']),
                        'payload' => ['eventType' => 'Demo', 'data' => fake()->words(3)],
                        'payload_hash' => fake()->sha1(),
                        'processed_at' => $when,
                        'created_at' => $when,
                        'updated_at' => $when,
                    ]);
                }
            }
        }
    }

    /**
     * Mixed-status ActionRequests so the queue + sparkline both populate.
     *
     * @param  Collection<string, ServiceConnection>  $services
     */
    private function seedActionRequests(Collection $services): void
    {
        $now = CarbonImmutable::now();
        $titles = [
            ['title' => 'Severance', 'type' => 'delete_series', 'svc' => 'sonarr', 'detail' => 'Cascading from Emby delete · 2 seasons · 18 episodes'],
            ['title' => 'Dune: Part Two (2024)', 'type' => 'delete_movie', 'svc' => 'radarr', 'detail' => '4K HDR · 78.2 GB'],
            ['title' => 'Industry', 'type' => 'delete_series', 'svc' => 'sonarr', 'detail' => 'Approved · awaiting executor'],
            ['title' => 'True Detective S01', 'type' => 'delete_series', 'svc' => 'sonarr', 'detail' => 'Sonarr returned 401 · check API key'],
            ['title' => 'Library scan — Movies', 'type' => 'emby_library_scan', 'svc' => 'emby', 'detail' => 'Picked up 1 added title'],
            ['title' => 'Library scan — TV', 'type' => 'emby_library_scan', 'svc' => 'emby', 'detail' => 'Removed 4 stale episodes'],
        ];

        $statuses = [
            ActionRequestStatus::Pending,
            ActionRequestStatus::Pending,
            ActionRequestStatus::Approved,
            ActionRequestStatus::Failed,
            ActionRequestStatus::Completed,
            ActionRequestStatus::Completed,
        ];

        foreach ($titles as $i => $row) {
            $service = $services->get($row['svc']);
            $created = $now->subMinutes(random_int(2, 720));

            ActionRequest::create([
                'webhook_event_id' => null,
                'type' => $row['type'],
                'source_service' => $row['svc'],
                'target_service' => $service?->type->value ?? 'sonarr',
                'status' => $statuses[$i],
                'requires_approval' => $statuses[$i] === ActionRequestStatus::Pending,
                'approved_by' => null,
                'payload' => ['title' => $row['title'], 'detail' => $row['detail']],
                'result' => $statuses[$i] === ActionRequestStatus::Completed
                    ? ['success' => true, 'message' => $row['detail']]
                    : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }
    }

    /**
     * EmbyUserLink + 14d of playback so Now Playing has a session and
     * Watch History has rows. Skip if no Emby connection exists.
     *
     * @param  Collection<string, ServiceConnection>  $services
     */
    private function seedEmbyActivity(User $admin, Collection $services): void
    {
        if ($services->get('emby') === null) {
            return;
        }

        $link = EmbyUserLink::firstOrCreate(
            ['user_id' => $admin->id],
            ['emby_user_id' => 'demo-emby-user', 'emby_username' => 'demo'],
        );

        $titles = [
            ['title' => 'The Bear', 'type' => 'episode', 'series' => 'The Bear'],
            ['title' => 'Severance', 'type' => 'episode', 'series' => 'Severance'],
            ['title' => 'Dune: Part Two', 'type' => 'movie', 'series' => null],
            ['title' => 'Furiosa', 'type' => 'movie', 'series' => null],
            ['title' => 'Shogun', 'type' => 'episode', 'series' => 'Shogun'],
            ['title' => 'Industry', 'type' => 'episode', 'series' => 'Industry'],
        ];

        $now = CarbonImmutable::now();

        for ($day = 0; $day < 14; $day++) {
            $plays = random_int(1, 3);
            for ($p = 0; $p < $plays; $p++) {
                $title = $titles[array_rand($titles)];
                $when = $now->subDays($day)->subMinutes(random_int(0, 60 * 23));
                EmbyActivity::create([
                    'emby_user_link_id' => $link->id,
                    'media_type' => $title['type'],
                    'media_title' => $title['title'],
                    'series_title' => $title['series'],
                    'emby_item_id' => fake()->uuid(),
                    'action' => 'finished',
                    'duration_ticks' => random_int(20_000_000_000, 90_000_000_000),
                    'play_position' => random_int(15_000_000_000, 90_000_000_000),
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
            }
        }

        // One in-flight 'played' event so Now Playing lights up.
        EmbyActivity::create([
            'emby_user_link_id' => $link->id,
            'media_type' => 'episode',
            'media_title' => 'The Bear',
            'series_title' => 'The Bear',
            'emby_item_id' => fake()->uuid(),
            'action' => 'played',
            'duration_ticks' => 32_700_000_000,
            'play_position' => 18_300_000_000,
            'created_at' => $now->subMinutes(3),
            'updated_at' => $now->subMinutes(3),
        ]);
    }

    /**
     * Recent ActivityLog rows so /activity isn't empty.
     *
     * @param  Collection<string, ServiceConnection>  $services
     */
    private function seedActivityLog(User $admin, Collection $services): void
    {
        $now = CarbonImmutable::now();
        $entries = [
            ['svc' => 'emby', 'action' => 'playback.start', 'desc' => 'rachel started The Bear · S03E08 on Living Room'],
            ['svc' => 'sonarr', 'action' => 'Download', 'desc' => 'Severance · S02E07 · 1080p · 4.21 GB'],
            ['svc' => 'seerr', 'action' => 'MEDIA_APPROVED', 'desc' => 'Anora (2024) approved by james'],
            ['svc' => 'radarr', 'action' => 'Grab', 'desc' => 'Civil War (2024) · 2160p HDR · indexer: nzb.cat'],
            ['svc' => 'emby', 'action' => 'library.deleted', 'desc' => 'Series removed: Industry · cascade pending'],
            ['svc' => 'sonarr', 'action' => 'Health', 'desc' => 'Indexer rss feed timeout: nzb.cat (recovered 23:59)'],
            ['svc' => 'seerr', 'action' => 'MEDIA_PENDING', 'desc' => 'Beetlejuice Beetlejuice (2024) · requested by sam'],
            ['svc' => 'emby', 'action' => 'playback.stop', 'desc' => 'james stopped Furiosa · 1h 47m watched · 86%'],
            ['svc' => 'radarr', 'action' => 'MovieAdded', 'desc' => 'Late Night with the Devil (2023) · monitored'],
            ['svc' => 'sonarr', 'action' => 'SeriesAdd', 'desc' => 'Shogun (2024) · monitor: future · profile: Bluray-1080p'],
        ];

        foreach ($entries as $i => $row) {
            $service = $services->get($row['svc']);
            $when = $now->subMinutes($i * 7 + random_int(1, 4));
            ActivityLog::create([
                'user_id' => $admin->id,
                'service_connection_id' => $service?->id,
                'action' => $row['action'],
                'description' => '['.self::DEMO_TAG.'] '.$row['desc'],
                'metadata' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }
    }

    /**
     * Last-60-min metric strip per service so service health renders
     * something out-of-the-box.
     *
     * @param  Collection<string, ServiceConnection>  $services
     */
    private function seedServiceMetrics(Collection $services): void
    {
        $now = CarbonImmutable::now();

        foreach ($services as $service) {
            for ($minute = 0; $minute < 60; $minute++) {
                $roll = random_int(1, 100);
                $status = match (true) {
                    $roll <= 95 => HealthStatus::Healthy,
                    $roll <= 99 => HealthStatus::Unhealthy,
                    default => HealthStatus::Unknown,
                };

                ServiceMetric::create([
                    'service_connection_id' => $service->id,
                    'status' => $status,
                    'latency_ms' => $status === HealthStatus::Unhealthy
                        ? random_int(800, 2400)
                        : random_int(20, 220),
                    'message' => null,
                    'recorded_at' => $now->subMinutes(60 - $minute)->subSeconds(random_int(0, 30)),
                ]);
            }
        }
    }
}
