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

/**
 * Seerr with one pending TV request (id 1) and three approved movies, all by
 * one requester: $title is the TV show's name, $requester the display name.
 */
function requestsCardFakes(string $title = 'Severance', string $requester = 'Example Requester'): void
{
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    $requestedBy = ['id' => 7, 'displayName' => $requester, 'email' => 'requester@example.com'];
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
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => $title, 'posterPath' => '/example.jpg', 'firstAirDate' => '2022-02-17']),
        'seerr.local:5055/*' => Http::response([]),
    ]);
}

/**
 * Each way card 1's controls fail, as a ' | ' list: a control past the card's
 * edge, an icon button squeezed below its 28 px, or an icon below its 14 px.
 * A clip (overflow-hidden, min-w-0 on the anchors) cannot pass for a fit.
 */
function requestsCardControlsScript(): string
{
    return '(() => { const card = document.querySelector("[data-request-card=\\"1\\"]").getBoundingClientRect(); const controls = [...document.querySelectorAll("[data-request-card=\\"1\\"] a, [data-request-card=\\"1\\"] button")]; const name = (control) => (control.textContent.trim() || control.getAttribute("aria-label") || control.getAttribute("title") || control.tagName).slice(0, 24); return [...controls.filter((control) => { const rect = control.getBoundingClientRect(); return rect.left < card.left - 1 || rect.right > card.right + 1; }).map((control) => `${name(control)} outside ${Math.round(control.getBoundingClientRect().left)}..${Math.round(control.getBoundingClientRect().right)} (card ${Math.round(card.left)}..${Math.round(card.right)})`), ...controls.filter((control) => control.classList.contains("size-7") && (control.getBoundingClientRect().width < 27.5 || control.getBoundingClientRect().height < 27.5)).map((control) => `${name(control)} squeezed to ${Math.round(control.getBoundingClientRect().width)}x${Math.round(control.getBoundingClientRect().height)}`), ...controls.flatMap((control) => [...control.querySelectorAll("svg")]).filter((icon) => icon.getBoundingClientRect().width < 13.5 || icon.getBoundingClientRect().height < 13.5).map((icon) => `an icon shrank to ${Math.round(icon.getBoundingClientRect().width)}px`)].join(" | "); })()';
}

test('a pending request card keeps every action inside the card on desktop', function (int $width): void {
    requestsCardFakes();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->assertNoSmoke();
    $webpage->resize($width, 1117);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-request-card=\"1\"] [data-request-delete]")'));

    $webpage->assertScript(requestsCardControlsScript(), '')
        ->assertScript(phoneOverlapScript('[data-request-card="1"]'), '');
})->with([1728, 1280, 1024]);

test('a request card breaks an unbroken title and an email requester inside the card', function (int $width): void {
    requestsCardFakes('ExampleUnbrokenTitleThatRunsPastTheCard0123456789', 'example.requester.with.a.long.address@example.com');
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->assertNoSmoke();
    $webpage->resize($width, 1117);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-request-card=\\"1\\"] [data-request-delete]")'));

    $webpage->assertSeeIn('[data-request-card="1"]', 'ExampleUnbrokenTitle')
        ->assertScript('(() => { const card = document.querySelector("[data-request-card=\\"1\\"]"); const right = card.getBoundingClientRect().right - parseFloat(getComputedStyle(card).paddingRight); const walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT); const range = document.createRange(); const past = []; while (walker.nextNode()) { if (walker.currentNode.textContent.trim() === "") { continue; } range.selectNodeContents(walker.currentNode); const end = Math.max(...[...range.getClientRects()].map((rect) => rect.right)); if (end > right + 1) { past.push(`"${walker.currentNode.textContent.trim().slice(0, 24)}" ends at ${Math.round(end)} (card content ${Math.round(right)})`); } } return past.join(" | "); })()', '')
        ->assertScript(requestsCardControlsScript(), '');
})->with([1728, 1024]);
