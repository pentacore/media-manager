<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Confirms a manual movie delete from the show page goes through the action
 * pipeline (approval queued) rather than calling Radarr directly, per
 * SD-2026-09-27 task 1. Fixture shapes follow tests/Browser/MediaReplacementDialogTest.php.
 */
test('member queues a movie delete for approval from the show page', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_movie',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    Http::fake([
        'radarr.local:7878/api/v3/movie/10' => Http::response([
            'id' => 10,
            'title' => 'A Movie',
            'titleSlug' => 'a-movie-2026',
            'year' => 2026,
            'status' => 'released',
            'monitored' => true,
            'hasFile' => true,
            'qualityProfileId' => 1,
            'sizeOnDisk' => 5_000_000_000,
            'images' => [],
            'overview' => 'A movie about a thing.',
            'runtime' => 120,
            'studio' => 'Example Studio',
            'rootFolderPath' => '/movies',
        ]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.show', ['id' => 10], absolute: false))
        ->assertSee('A Movie')
        ->assertNoSmoke()
        ->click('[data-delete-trigger]')
        ->assertSeeIn('[data-delete-description]', 'approval in the')
        ->click('[data-delete-confirm]')
        ->assertSee('Deletion queued for approval in the Action Queue.')
        ->assertNoSmoke();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');

    $actionRequest = ActionRequest::query()->where('type', 'delete_movie')->sole();
    expect($actionRequest->origin)->toBe('manual');
    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending);
    expect($actionRequest->payload)->toMatchArray([
        'radarr_movie_id' => 10,
        'delete_files' => false,
    ]);
});
