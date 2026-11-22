<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Series 7 with one season and one episode.
 */
function collapsibleFakeSonarr(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Example Series Title', 'titleSlug' => 'example-series-title', 'year' => 2026, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [],
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 0, 'sizeOnDisk' => 0]]],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 1, 'episodeFileCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 70, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Example Pilot Episode', 'airDate' => '2026-02-18', 'hasFile' => false, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/release*' => Http::response([]),
    ]);
}

/**
 * Reads the trigger's `aria-controls` and the element that id points at, as
 * JSON: `{controls, expanded, found, slot, state, hidden, holdsContent}`.
 */
function collapsibleControlsScript(string $triggerSelector, string $contentSelector): string
{
    return sprintf(<<<'JS_WRAP'
    (() => {
        const trigger = document.querySelector(%s);
        const controls = trigger?.getAttribute('aria-controls') ?? null;
        const content = controls ? document.getElementById(controls) : null;
        return JSON.stringify({
            controls,
            expanded: trigger?.getAttribute('aria-expanded') ?? null,
            found: content !== null,
            slot: content?.dataset.slot ?? null,
            state: content?.dataset.state ?? null,
            hidden: content?.hidden ?? null,
            holdsContent: Boolean(content?.querySelector(%s)),
        });
    })()
    JS_WRAP, json_encode($triggerSelector, JSON_THROW_ON_ERROR), json_encode($contentSelector, JSON_THROW_ON_ERROR));
}

/**
 * @return array{controls: ?string, expanded: ?string, found: bool, slot: ?string, state: ?string, hidden: ?bool, holdsContent: bool}
 */
function collapsibleControls(mixed $webpage, string $triggerSelector, string $contentSelector): array
{
    return json_decode((string) $webpage->script(collapsibleControlsScript($triggerSelector, $contentSelector)), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A closed trigger already names a real id in `aria-controls`: reka keeps the
 * content element in the DOM, hidden and empty, while it is closed. Opening
 * fills that same element.
 */
function collapsibleAssertControlsItsContent(mixed $webpage, string $triggerSelector, string $contentSelector): void
{
    $webpage->script(appHydratedScript());

    $closed = collapsibleControls($webpage, $triggerSelector, $contentSelector);

    expect($closed['controls'])->toBeString()->not->toBe('')
        ->and($closed)->toBe([
            'controls' => $closed['controls'],
            'expanded' => 'false',
            'found' => true,
            'slot' => 'collapsible-content',
            'state' => 'closed',
            'hidden' => true,
            'holdsContent' => false,
        ]);

    $webpage->click($triggerSelector)
        ->assertPresent($contentSelector);

    $opened = collapsibleControls($webpage, $triggerSelector, $contentSelector);

    expect($opened)->toBe([
        'controls' => $closed['controls'],
        'expanded' => 'true',
        'found' => true,
        'slot' => 'collapsible-content',
        'state' => 'open',
        'hidden' => false,
        'holdsContent' => true,
    ]);
}

test('a closed sidebar group points aria-controls at its sub-menu', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();

    collapsibleAssertControlsItsContent($webpage, '[data-nav-group="Configuration"]', '[data-nav-item="Connections"]');
});

test('the closed chat template help points aria-controls at its panel', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))->assertNoSmoke();

    collapsibleAssertControlsItsContent($webpage, '[data-template-help-toggle]', '[data-template-help] ol');
});

test('the closed email login toggle points aria-controls at the login form', function (): void {
    config()->set('services.authentik.client_id', 'example-client');

    $webpage = visit(route('login', absolute: false))->assertNoSmoke();

    collapsibleAssertControlsItsContent($webpage, '[data-login-local-toggle]', '[data-test="login-button"]');
});

test('a closed season card points aria-controls at its episode list', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    collapsibleFakeSonarr();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->assertNoSmoke();
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-season-card=\"1\"] [data-season-toggle]') !== null"));

    collapsibleAssertControlsItsContent($webpage, '[data-season-card="1"] [data-season-toggle]', '[data-episode-actions="70"]');
});
