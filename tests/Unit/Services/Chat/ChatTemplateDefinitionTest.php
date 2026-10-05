<?php

declare(strict_types=1);

use App\Services\Chat\ChatTemplateDefinition;
use App\Services\Chat\ChatTemplateParser;

function chatTemplateDefinition(): ChatTemplateDefinition
{
    return new ChatTemplateDefinition(new ChatTemplateParser);
}

/**
 * @return array<string, mixed>
 */
function chatTemplateVariable(string $name, string $type, array $overrides = []): array
{
    return array_replace(['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'default' => null, 'options' => null], $overrides);
}

test('a consistent definition has no errors', function (): void {
    expect(chatTemplateDefinition()->errors('Check {{anime:title,year}} S{{season}} in {{lang}}', [
        chatTemplateVariable('anime', 'series'),
        chatTemplateVariable('season', 'number', ['default' => '1']),
        chatTemplateVariable('lang', 'choice', ['options' => ['English', 'Swedish'], 'default' => 'Swedish']),
    ]))->toBe([]);
});

test('inconsistent definitions report the offending field', function (string $body, array $variables, string $field, string $fragment): void {
    $errors = chatTemplateDefinition()->errors($body, $variables);

    expect($errors)->toHaveKey($field)
        ->and(implode(' ', $errors[$field]))->toContain($fragment);
})->with([
    'grammar error' => ['{{anime:poster}}', [chatTemplateVariable('anime', 'series')], 'body', 'unknown part'],
    'token without settings' => ['{{anime}}', [], 'body', '{{anime}} has no variable settings'],
    'unused variable' => ['Hello', [chatTemplateVariable('q', 'text')], 'variables.0.name', 'not used in the template'],
    'duplicate name' => ['{{q}}', [chatTemplateVariable('q', 'text'), chatTemplateVariable('q', 'number')], 'variables.1.name', 'already uses this name'],
    'parts on a text variable' => ['{{q:title}}', [chatTemplateVariable('q', 'text')], 'body', 'only series and movie variables have parts'],
    'options on a text variable' => ['{{q}}', [chatTemplateVariable('q', 'text', ['options' => ['a', 'b']])], 'variables.0.options', 'Only choice variables have options'],
    'choice with one option' => ['{{q}}', [chatTemplateVariable('q', 'choice', ['options' => ['a', 'a', '']])], 'variables.0.options', 'at least two different options'],
    'default on a series' => ['{{q}}', [chatTemplateVariable('q', 'series', ['default' => 'Frieren'])], 'variables.0.default', 'cannot have a default'],
    'non-integer number default' => ['{{q}}', [chatTemplateVariable('q', 'number', ['default' => 'two'])], 'variables.0.default', 'whole number'],
    'negative number default' => ['{{q}}', [chatTemplateVariable('q', 'number', ['default' => '-1'])], 'variables.0.default', 'whole number between 0 and 100000'],
    'number default above the maximum' => ['{{q}}', [chatTemplateVariable('q', 'number', ['default' => '100001'])], 'variables.0.default', 'whole number between 0 and 100000'],
    'choice default outside options' => ['{{q}}', [chatTemplateVariable('q', 'choice', ['options' => ['a', 'b'], 'default' => 'c'])], 'variables.0.default', 'one of the options'],
]);

test('number defaults at the fill-time bounds are accepted', function (string $default): void {
    expect(chatTemplateDefinition()->errors('{{q}}', [chatTemplateVariable('q', 'number', ['default' => $default])]))->toBe([]);
})->with(['minimum' => '0', 'maximum' => '100000']);

test('normalisation trims, dedupes and drops settings a type does not use', function (): void {
    expect(chatTemplateDefinition()->normalize([
        chatTemplateVariable('lang', 'choice', ['label' => ' Language ', 'options' => [' English ', 'Swedish', null, '', 'English'], 'default' => 'English']),
        chatTemplateVariable('anime', 'series', ['default' => 'ignored', 'options' => ['ignored']]),
        chatTemplateVariable('season', 'number', ['default' => '', 'options' => []]),
    ]))->toBe([
        ['name' => 'lang', 'label' => 'Language', 'type' => 'choice', 'default' => 'English', 'options' => ['English', 'Swedish']],
        ['name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null],
        ['name' => 'season', 'label' => 'Season', 'type' => 'number', 'default' => null, 'options' => null],
    ]);
});
