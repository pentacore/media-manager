<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * Every chat template route, keyed by name: [method, route name, needs a
 * template id]. The guard test below fails when a route is added without a
 * row here.
 *
 * @return array<string, array{string, string, bool}>
 */
function chatTemplateAuthorizationRoutes(): array
{
    return [
        'index' => ['GET', 'ai.templates.index', false],
        'create' => ['GET', 'ai.templates.create', false],
        'draft' => ['POST', 'ai.templates.draft', false],
        'store' => ['POST', 'ai.templates.store', false],
        'options' => ['GET', 'ai.templates.options', false],
        'library' => ['GET', 'ai.templates.library', false],
        'preview' => ['POST', 'ai.templates.preview', false],
        'edit' => ['GET', 'ai.templates.edit', true],
        'update' => ['PATCH', 'ai.templates.update', true],
        'destroy' => ['DELETE', 'ai.templates.destroy', true],
        'pin' => ['PATCH', 'ai.templates.pin', true],
        'render' => ['POST', 'ai.templates.render', true],
    ];
}

dataset('chat template routes', chatTemplateAuthorizationRoutes());

/**
 * guest: 401 (JSON), viewer and member: 403, admin: past the gate (a page,
 * a redirect or a validation error, never 401/403).
 */
dataset('chat template roles', [
    'guest' => [null, 401],
    'viewer' => ['viewer', 403],
    'member' => ['member', 403],
    'admin' => ['admin', null],
]);

test('the route dataset covers every chat template route', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'ai.templates.'))
        ->sort()
        ->values()
        ->all();

    $covered = collect(chatTemplateAuthorizationRoutes())
        ->map(fn (array $row): string => $row[1])
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe($covered);
});

test('each chat template route is gated to admins', function (string $method, string $routeName, bool $needsTemplate, ?string $role, ?int $expected): void {
    $user = match ($role) {
        'viewer' => User::factory()->create(),
        'member' => User::factory()->member()->create(),
        'admin' => User::factory()->admin()->create(),
        default => null,
    };

    $parameters = $needsTemplate
        ? [ChatTemplate::factory()->for($user ?? User::factory()->admin()->create())->create()]
        : [];

    if ($user instanceof User) {
        $this->actingAs($user);
    }

    $response = $this->json($method, route($routeName, $parameters));

    if ($expected !== null) {
        $response->assertStatus($expected);

        return;
    }

    expect($response->getStatusCode())->not->toBeIn([401, 403, 404]);
})->with('chat template routes')->with('chat template roles');

test('members and viewers cannot reach templates', function (string $role): void {
    $user = $role === 'member' ? User::factory()->member()->create() : User::factory()->create();

    $this->actingAs($user)->get(route('ai.templates.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ai.templates.create'))->assertForbidden();
    $this->actingAs($user)->post(route('ai.templates.store'), [])->assertForbidden();
})->with(['member', 'viewer']);

test('templates are unavailable while AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('ai.templates.index'))
        ->assertNotFound();
});

test("another admin's template is not found", function (string $method, string $routeName): void {
    $chatTemplate = ChatTemplate::factory()->create(['name' => 'Theirs']);

    $this->actingAs(User::factory()->admin()->create())
        ->json($method, route($routeName, $chatTemplate), [
            'name' => 'Mine now', 'body' => 'Hello', 'variables' => [], 'auto_send' => false, 'pinned' => false,
        ])
        ->assertNotFound();

    expect($chatTemplate->fresh())
        ->not->toBeNull()
        ->name->toBe('Theirs')
        ->pinned->toBeFalse();
})->with([
    'edit' => ['GET', 'ai.templates.edit'],
    'update' => ['PATCH', 'ai.templates.update'],
    'destroy' => ['DELETE', 'ai.templates.destroy'],
    'pin' => ['PATCH', 'ai.templates.pin'],
]);
