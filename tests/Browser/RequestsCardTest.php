<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Batch 3h-3, Task 12: a Pending request card's action row wraps at every
 * width, so Approve, Decline, the external links and the admin's Edit,
 * Retry and Delete stay inside the card in the desktop grid.
 */

function requestsCardFakes(): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    $requestedBy = ['id' => 7, 'displayName' => 'Example Requester', 'email' => 'requester@example.com'];
    $requests = [
        ['id' => 1, 'status' => 1, 'type' => 'tv', 'media' => ['mediaType' => 'tv', 'tmdbId' => 95396, 'tvdbId' => 371980, 'status' => 3],
            'requestedBy' => $requestedBy, 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => [['seasonNumber' => 1]]],
    ];
    foreach ([2, 3, 4] as $id) {
        $requests[] = ['id' => $id, 'status' => 2, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 438630 + $id, 'status' => 5],
            'requestedBy' => $requestedBy, 'createdAt' => '2026-10-0'.$id.'T00:00:00Z'];
    }

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 4, 'pending' => 1, 'approved' => 3, 'declined' => 0, 'movie' => 3, 'tv' => 1]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 4], 'results' => $requests]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [$requestedBy]]),
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => 'Severance', 'posterPath' => '/example.jpg', 'firstAirDate' => '2022-02-17']),
        'seerr.local:5055/*' => Http::response([]),
    ]);
}

test('a pending request card keeps every action inside the card on desktop', function (int $width): void {
    requestsCardFakes();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->assertNoSmoke();
    $webpage->resize($width, 1117);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-request-card=\"1\"] [data-request-delete]")'));

    $webpage->assertScript('(() => { const card = document.querySelector("[data-request-card=\"1\"]").getBoundingClientRect(); return [...document.querySelectorAll("[data-request-card=\"1\"] a, [data-request-card=\"1\"] button")].every((control) => { const rect = control.getBoundingClientRect(); return rect.left >= card.left - 1 && rect.right <= card.right + 1; }); })()', true)
        ->assertScript(phoneOverlapScript('[data-request-card="1"]'), '');
})->with([1728, 1280, 1024]);
