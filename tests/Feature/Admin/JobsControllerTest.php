<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ServiceCheckBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('guests are redirected from /admin/jobs', function (): void {
    $this->get(route('admin.jobs.index'))->assertRedirect(route('login'));
});

test('non-admins cannot view /admin/jobs', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('admin.jobs.index'))
        ->assertForbidden();
});

test('admins see all three sections rendered with their counts', function (): void {
    $admin = User::factory()->admin()->create();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\FakeJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => Date::now()->getTimestamp(),
        'created_at' => Date::now()->getTimestamp(),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => Str::uuid()->toString(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\BrokenJob']),
        'exception' => "RuntimeException: Something exploded\nat /app/...",
        'failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Jobs/Index')
            ->has('queued', 1)
            ->where('queued.0.class', 'App\\Jobs\\FakeJob')
            ->has('failed', 1)
            ->where('failed.0.class', 'App\\Jobs\\BrokenJob')
            ->where('failed.0.exception_class', 'RuntimeException')
            ->has('batches')
            ->has('scheduled')
        );
});

test('batches panel surfaces job_batches rows and flags the cached current health batch', function (): void {
    $admin = User::factory()->admin()->create();

    $batchId = (string) Str::uuid();
    DB::table('job_batches')->insert([
        'id' => $batchId,
        'name' => 'service-health',
        'total_jobs' => 3,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => '',
        'cancelled_at' => null,
        'created_at' => Date::now()->getTimestamp(),
        'finished_at' => null,
    ]);
    Cache::put(ServiceCheckBatch::CACHE_KEY_HEALTH, $batchId, now()->addDay());

    $this->actingAs($admin)
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('batches', 1)
            ->where('batches.0.id', $batchId)
            ->where('batches.0.name', 'service-health')
            ->where('batches.0.total_jobs', 3)
            ->where('batches.0.pending_jobs', 1)
            ->where('batches.0.status', 'running')
            ->where('batches.0.is_current_health', true)
            ->where('batches.0.is_current_versions', false)
        );
});

test('scheduled commands include the existing recurring tasks', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // We don't pin the order — just assert the catalogue isn't empty
            // and contains a known signature command.
            ->where('scheduled', fn ($scheduled): bool => collect($scheduled)
                ->contains(fn ($entry): bool => str_contains((string) $entry['command'], 'library:refresh-intervention-count')))
        );
});
