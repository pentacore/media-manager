<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a login page in front of Seerr shows an error on the requests page, not an empty list', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/api/v1/request*' => Http::response('<html><body>Sign in</body></html>')]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-requests-error]', 'Seerr is unavailable — ask an admin to check the connection.')
        ->assertDontSee('No requests in this view.')
        ->assertCount('[data-request-card]', 0);
});
