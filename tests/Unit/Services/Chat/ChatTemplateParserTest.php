<?php

declare(strict_types=1);

use App\Services\Chat\ChatTemplateParser;
use App\Services\Chat\ChatTemplateToken;
use App\Services\Chat\ParsedChatTemplate;

function parseChatTemplate(string $body): ParsedChatTemplate
{
    return new ChatTemplateParser()->parse($body);
}

test('a body without tokens is one literal segment', function (): void {
    $parsed = parseChatTemplate('Check Sonarr and Radarr for stuck downloads');

    expect($parsed->segments)->toBe(['Check Sonarr and Radarr for stuck downloads'])
        ->and($parsed->names())->toBe([])
        ->and($parsed->isValid())->toBeTrue();
});

test('tokens split the body and keep their parts in written order', function (): void {
    $parsed = parseChatTemplate('Check {{anime:id,title}} S{{season}}E{{episode}}');

    expect($parsed->isValid())->toBeTrue()
        ->and($parsed->segments[0])->toBe('Check ')
        ->and($parsed->segments[1])->toEqual(new ChatTemplateToken('anime', ['id', 'title'], '{{anime:id,title}}'))
        ->and($parsed->segments[2])->toBe(' S')
        ->and($parsed->segments[3])->toEqual(new ChatTemplateToken('season', [], '{{season}}'))
        ->and($parsed->names())->toBe(['anime', 'season', 'episode']);
});

test('a repeated name is one variable but every occurrence is a token', function (): void {
    $parsed = parseChatTemplate('{{anime:title}} again: {{anime:id}}');

    expect($parsed->names())->toBe(['anime'])
        ->and($parsed->tokens())->toHaveCount(2)
        ->and($parsed->tokens()[1]->parts)->toBe(['id']);
});

test('multibyte literal text is preserved around tokens', function (): void {
    $parsed = parseChatTemplate('Frieren — {{x}} ✓');

    expect($parsed->segments)->toEqual(['Frieren — ', new ChatTemplateToken('x', [], '{{x}}'), ' ✓']);
});

test('malformed placeholders are reported and kept as literal text', function (string $body, string $messageFragment): void {
    $parsed = parseChatTemplate($body);

    expect($parsed->isValid())->toBeFalse()
        ->and($parsed->tokens())->toBe([])
        ->and(implode(' ', $parsed->errors))->toContain($messageFragment)
        ->and(implode('', $parsed->segments))->toBe($body);
})->with([
    'spaces inside braces' => ['Check {{ anime }}', 'is not a valid placeholder'],
    'uppercase name' => ['{{Anime}}', 'is not a valid placeholder'],
    'leading digit' => ['{{1anime}}', 'is not a valid placeholder'],
    'empty braces' => ['{{}}', 'is not a valid placeholder'],
    'empty parts' => ['{{anime:}}', 'is not a valid placeholder'],
    'name too long' => [sprintf('{{%s}}', str_repeat('a', 33)), 'is not a valid placeholder'],
    'unknown part' => ['{{anime:poster}}', 'unknown part "poster"'],
    'duplicate part' => ['{{anime:title,title}}', 'repeats a part'],
    'unclosed open' => ['Check {{anime', 'not part of a complete placeholder'],
    'stray close' => ['Check anime}}', 'not part of a complete placeholder'],
]);
