<?php

declare(strict_types=1);

use App\Models\User;
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
            ->has('scheduled')
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
