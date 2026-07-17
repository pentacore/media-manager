<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('viewer can browse the subtitle center', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);

    Http::fake([
        'bazarr.test/api/system/health' => Http::response(['data' => []]),
        'bazarr.test/api/episodes/wanted*' => Http::response(['data' => [], 'total' => 0]),
        'bazarr.test/api/movies/wanted*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    $this->actingAs(User::factory()->create());

    visit(route('bazarr.overview', ['connection' => $bazarr->id], false))
        ->assertSee('Subtitle Center')
        ->assertSee('Missing subtitles')
        ->assertNoSmoke()
        ->click('@subtitle-tab-missing')
        ->assertPathIs('/subtitles/missing')
        ->assertSee('Nothing is currently missing')
        ->assertNoSmoke()
        ->click('@subtitle-tab-library')
        ->assertPathIs('/subtitles/library')
        ->assertSee('No subtitle inventory found')
        ->assertNoSmoke()
        ->click('@subtitle-tab-history')
        ->assertPathIs('/subtitles/history')
        ->assertSee('No subtitle history found')
        ->assertNoSmoke();
});
