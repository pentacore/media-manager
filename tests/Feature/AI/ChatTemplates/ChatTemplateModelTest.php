<?php

declare(strict_types=1);

use App\Enums\ChatTemplateVariableType;
use App\Models\ChatTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;

test('the factory builds a usable template with cast columns', function (): void {
    $chatTemplate = ChatTemplate::factory()->subtitleCheck()->pinned()->autoSend()
        ->lastUsedAt(CarbonImmutable::parse('2026-10-01 12:00:00'))
        ->create();

    expect($chatTemplate->body)->toBe('Check {{anime:title,year}} S{{season}}E{{episode}} for subtitles')
        ->and($chatTemplate->variables)->toHaveCount(3)
        ->and($chatTemplate->variables[0])->toBe([
            'name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null,
        ])
        ->and($chatTemplate->pinned)->toBeTrue()
        ->and($chatTemplate->auto_send)->toBeTrue()
        ->and($chatTemplate->last_used_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a template is owned only by its user', function (): void {
    $owner = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($owner)->create();

    expect($chatTemplate->isOwnedBy($owner))->toBeTrue()
        ->and($chatTemplate->isOwnedBy($other))->toBeFalse()
        ->and($owner->chatTemplates()->pluck('id')->all())->toBe([$chatTemplate->id]);
});

test('deleting a user deletes their templates', function (): void {
    $owner = User::factory()->admin()->create();
    ChatTemplate::factory()->for($owner)->count(2)->create();

    $owner->delete();

    expect(ChatTemplate::query()->count())->toBe(0);
});

test('variable types expose their domain predicates', function (ChatTemplateVariableType $type, bool $isLibrary, bool $supportsDefault, bool $requiresOptions): void {
    expect($type->isLibrary())->toBe($isLibrary)
        ->and($type->supportsDefault())->toBe($supportsDefault)
        ->and($type->requiresOptions())->toBe($requiresOptions);
})->with([
    'text' => [ChatTemplateVariableType::Text, false, true, false],
    'number' => [ChatTemplateVariableType::Number, false, true, false],
    'choice' => [ChatTemplateVariableType::Choice, false, true, true],
    'series' => [ChatTemplateVariableType::Series, true, false, false],
    'movie' => [ChatTemplateVariableType::Movie, true, false, false],
]);
