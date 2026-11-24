<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/*
 * The layout gate (tests/Browser/Support/MobileLayout.php) on the
 * 375x812 phone in light mode.
 * This class also holds the phone-only checks: the 40 px touch-target sweeps,
 * the mid-word wrap check, the measuring scripts' own tests and the route guard.
 */

mobileLayoutGateTests('phone', 'light');

/**
 * @param  array<string, int|string>  $parameters
 */
test('every control on a page is a 40 px target on a phone', function (string $routeName, array $parameters, ?string $deferred): void {
    config()->set('mediamanager.ai.enabled', true);
    mobileLayoutFakeServices();
    $admin = User::factory()->admin()->create(['name' => 'Example Admin']);
    mobileLayoutSeedDetailModels($admin);
    $this->actingAs($admin);

    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, $deferred);

    $webpage->assertScript(phoneSmallTargetsScript(), '');
})->with(mobileLayoutTouchPages());

/**
 * @param  array<string, int|string>  $parameters
 */
test('every control on a guest page is a 40 px target on a phone', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
})->with(mobileLayoutGuestPages());

test('every control on a signed-in auth page is a 40 px target on a phone', function (string $routeName): void {
    $this->actingAs(User::factory()->member()->unverified()->create());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
})->with(mobileLayoutSignedInAuthPages());

/**
 * The titles are prose ("series — episode"): on a phone they wrap at a space,
 * and only an unbroken run (a file name) may split mid-word.
 */
test('a long spaced Subtitles title wraps at a space on a phone', function (): void {
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->member()->create());
    $title = 'Example Episode With A Fairly Long Name 2';

    $webpage = visit(route('bazarr.missing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, '[data-test="subtitle-item-episode-82"]');
    $webpage->assertScript(mobileLayoutMidWordBreaksScript('main', $title), '');

    $webpage->click('[data-test="subtitle-item-episode-82"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"]") && document.querySelector("[data-slot=\"sheet-content\"]").getAnimations().length === 0'));
    $webpage->assertScript(mobileLayoutMidWordBreaksScript('[data-slot="sheet-content"] [data-slot="sheet-header"]', $title), '');

    foreach (['bazarr.library' => '[data-test="subtitle-item-episode-82"]', 'bazarr.history' => '[data-test="history-filters"]'] as $routeName => $deferred) {
        $webpage->navigate(route($routeName, absolute: false))->assertNoSmoke();
        mobileLayoutSettle($webpage, $deferred);
        $webpage->assertScript(mobileLayoutMidWordBreaksScript('main', $title), '');
    }
});

test('the settle wait fails loudly when the deferred content never renders', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();

    expect(fn () => mobileLayoutSettle($webpage, '[data-never-rendered]'))
        ->toThrow('the deferred content [data-never-rendered] never rendered');
});

test('the overlap check names two overlapping controls and excuses nested or clipped ones', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-overlap-fixture style="position:relative;height:60px"><button style="position:absolute;left:0;top:0;width:100px;height:40px">One</button><button style="position:absolute;left:60px;top:10px;width:100px;height:40px">Two</button></div>`)');

    $overlap = $webpage->script(phoneOverlapScript());
    expect($overlap)->toContain('"One"')->toContain('"Two"')->toContain(' × ');

    // A disabled Button is pointer-events: none yet keeps its box, so it still collides.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:60px"><button disabled style="position:absolute;left:0;top:0;width:100px;height:40px;pointer-events:none">Off</button><button style="position:absolute;left:60px;top:10px;width:100px;height:40px">On</button></div>`');
    expect($webpage->script(phoneOverlapScript()))->toContain('"Off"')->toContain('"On"');

    // A small control wholly inside a bigger one's box (a checkbox on a card link) is the overlay pattern.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:120px"><a href="#" style="position:absolute;inset:0">Card</a><button style="position:absolute;left:8px;top:8px;width:40px;height:40px" aria-label="Pick">x</button></div>`');
    $webpage->assertScript(phoneOverlapScript(), '');

    // A control its scroller clips away overlaps nothing on screen.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:60px"><div style="width:100px;overflow:hidden;white-space:nowrap"><button style="margin-left:150px;width:80px;height:40px">Hidden</button></div><button style="position:absolute;left:150px;top:0;width:80px;height:40px">Shown</button></div>`');
    $webpage->assertScript(phoneOverlapScript(), '');

    // An inline link wrapping onto a second line is measured by its line boxes, not its bounding box,
    // so a taller button after it on the second line overlaps nothing.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="width:200px;font-size:16px;line-height:20px"><a href="#">An inline link that wraps here</a> <button style="height:40px;vertical-align:top">Next</button></div>`');
    $webpage->assertScript('(() => { const link = document.querySelector("[data-overlap-fixture] a"); const button = document.querySelector("[data-overlap-fixture] button"); const box = link.getBoundingClientRect(); const target = button.getBoundingClientRect(); return link.getClientRects().length === 2 && target.top < box.bottom && target.left < box.right && target.bottom > box.bottom; })()', true);
    $webpage->assertScript(phoneOverlapScript(), '');
});

test('the sliver check names a scroller that scrolls by a few pixels', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // A 32 px touch-target in a 32 px scroller: its 40 px ::after overhangs 4 px below.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-sliver-fixture style="height:32px;overflow-y:auto"><button class="touch-target" style="display:block;height:32px">Sliver</button></div>`)');
    expect($webpage->script(phoneScrollSliverScript()))->toContain('[data-sliver-fixture]')->toContain('by 4px');

    // Content that really scrolls is not a sliver.
    $webpage->script('document.querySelector("[data-sliver-fixture]").outerHTML = `<div data-sliver-fixture style="height:100px;overflow-y:auto"><div style="height:300px">Tall</div></div>`');
    $webpage->assertScript(phoneScrollSliverScript(), '');
});

test('the clipped-text check names text past its box and passes a touch target', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // Unbroken text inside a span is measured against its block, which a frame clips.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-clipped-fixture style="width:100px;overflow:hidden"><div><span>ExampleUnbrokenTextThatRunsPastItsBox</span></div></div>`)');

    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div[data-clipped-fixture] "ExampleUnbrokenTextThatRunsPastItsBox" cut sideways');

    // A touch-target's 40 px ::after is wider than its 24 px button, but its text fits.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture><button class="touch-target" style="width:24px;height:24px;padding:0;font-size:10px">OK</button></div>`');
    $webpage->assertScript('(() => { const button = document.querySelector("[data-clipped-fixture] button"); return parseFloat(getComputedStyle(button, "::after").width) > button.clientWidth; })()', true)
        ->assertScript(mobileLayoutClippedTextScript(), '');

    // Text that ends in an ellipsis is clipped on purpose.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="width:100px"><div class="truncate">ExampleUnbrokenTextThatRunsPastItsBox</div></div>`');
    $webpage->assertScript(mobileLayoutClippedTextScript(), '');
});

test('the clipped-text check finds the frame that cuts text above a box the text fits', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // A flex item grows to its unwrapped text, so the text fits its own box; the frame two levels up cuts it.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-clipped-fixture style="width:100px;overflow:hidden"><div style="display:flex"><span style="white-space:nowrap">Words that run past the frame</span></div></div>`)');
    $webpage->assertScript('(() => { const span = document.querySelector("[data-clipped-fixture] span"); return span.scrollWidth <= span.clientWidth && span.getBoundingClientRect().right > document.querySelector("[data-clipped-fixture]").getBoundingClientRect().right; })()', true);

    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div[data-clipped-fixture] "Words that run past the frame" cut sideways');

    // A position: absolute box escapes a frame that is not its containing block.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="position:relative;width:200px;height:40px"><div style="width:100px;height:20px;overflow:hidden"><span style="position:absolute;white-space:nowrap">Words past the frame</span></div></div>`');
    $webpage->assertScript(mobileLayoutClippedTextScript(), '');
});

test('the clipped-text check names text a line clamp or its own overflow cuts without an ellipsis', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // A line clamp hides its extra lines behind its own ellipsis, but cuts an unbroken word sideways.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-clipped-fixture style="width:100px;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2">An ExampleUnbrokenTextThatRunsPastItsBox</div>`)');

    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div[data-clipped-fixture] "An ExampleUnbrokenTextThatRunsPastItsBox" cut sideways');

    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="width:100px;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2">Many short words that wrap onto five or six lines in all</div>`');
    $webpage->assertScript('(() => { const box = document.querySelector("[data-clipped-fixture]"); return box.scrollHeight > box.clientHeight; })()', true)
        ->assertScript(mobileLayoutClippedTextScript(), '');

    // A box that clips its own text to one line hides the rest without an ellipsis.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="width:100px;height:20px;line-height:20px;overflow:hidden">Short words that wrap onto more lines</div>`');
    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div[data-clipped-fixture] "Short words that wrap onto more lines" cut down');

    // A clipping box that ends in an ellipsis cuts on purpose.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture class="truncate" style="width:100px"><span>ExampleUnbrokenTextThatRunsPastItsBox</span></div>`');
    $webpage->assertScript(mobileLayoutClippedTextScript(), '');
});

test('the clipped-text check passes text a scroller reveals', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // The paragraph is as wide as its scroller, so its text runs past it, but the scroller reaches it.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-clipped-fixture style="width:100px;overflow-x:auto"><p style="white-space:nowrap">ExampleUnbrokenTextThatRunsPastItsBox</p></div>`)');
    $webpage->assertScript('(() => { const scroller = document.querySelector("[data-clipped-fixture]"); return scroller.scrollWidth > scroller.clientWidth; })()', true)
        ->assertScript(mobileLayoutClippedTextScript(), '');

    // A frame inside the scroller still cuts.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="width:100px;overflow-x:auto"><div data-inner-frame style="width:80px;overflow:hidden"><p style="white-space:nowrap">ExampleUnbrokenTextThatRunsPastItsBox</p></div></div>`');
    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div[data-inner-frame] "ExampleUnbrokenTextThatRunsPastItsBox" cut sideways');
});

test('the phone gate classifies every page route', function (): void {
    $listed = collect([...mobileLayoutMemberPages(), ...mobileLayoutAdminPages(), ...mobileLayoutGuestPages(), ...mobileLayoutSignedInAuthPages(), ...mobileLayoutFlowAuthPages()])->map(fn (array $row): string => $row[0])->unique();
    $excluded = collect(mobileLayoutExcludedRouteNames())->keys();

    $pageRoutes = collect(mobileLayoutPageRouteNames());

    expect(mobileLayoutUnclassifiedRouteNames())->toBe([])
        ->and($listed->merge($excluded)->diff($pageRoutes)->values()->all())->toBe([])
        ->and($listed->intersect($excluded)->values()->all())->toBe([]);
});

test('the route guard catches an unlisted page behind auth or an auth guard', function (): void {
    Route::middleware(['web', 'auth'])->get('example-auth-page', fn (): Response => Inertia::render('Dashboard'))->name('example.auth-page');
    Route::middleware(['web', 'auth:web'])->get('example-guarded-page', fn (): Response => Inertia::render('Dashboard'))->name('example.guarded-page');

    expect(mobileLayoutUnclassifiedRouteNames())->toBe(['example.auth-page', 'example.guarded-page']);
});

test('the route guard catches an unlisted guest or public page', function (): void {
    Route::middleware(['web', 'guest'])->get('example-guest-page', fn (): Response => Inertia::render('Dashboard'))->name('example.guest-page');
    Route::middleware(['web', 'guest:web'])->get('example-guarded-guest-page', fn (): Response => Inertia::render('Dashboard'))->name('example.guarded-guest-page');
    Route::middleware('web')->get('example-public-page', fn (): Response => Inertia::render('Dashboard'))->name('example.public-page');

    expect(mobileLayoutUnclassifiedRouteNames())->toBe(['example.guest-page', 'example.guarded-guest-page', 'example.public-page']);
});
