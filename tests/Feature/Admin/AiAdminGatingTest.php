<?php

declare(strict_types=1);

use App\Models\User;

dataset('ai admin pages', [
    'ai settings' => ['admin.ai-settings.index'],
    'decision agent' => ['admin.decision-agent.index'],
    'ai usage' => ['admin.ai-usage.index'],
    'ai conversations' => ['admin.ai-conversations.index'],
    'ai prices' => ['admin.ai-prices.index'],
]);

dataset('non-ai admin pages', [
    'users' => ['admin.users.index'],
    'webhook log' => ['admin.webhook-log.index'],
    'jobs' => ['admin.jobs.index'],
]);

test('ai admin pages return 404 when AI is disabled', function (string $routeName): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route($routeName))
        ->assertNotFound();
})->with('ai admin pages');

test('ai admin pages load when AI is enabled', function (string $routeName): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route($routeName))
        ->assertOk();
})->with('ai admin pages');

test('ai admin mutation routes return 404 when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.refresh'))
        ->assertNotFound();
});

test('non-ai admin pages still load when AI is disabled', function (string $routeName): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route($routeName))
        ->assertOk();
})->with('non-ai admin pages');
