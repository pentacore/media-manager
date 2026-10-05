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
    $parsedChatTemplate = parseChatTemplate('Check Sonarr and Radarr for stuck downloads');

    expect($parsedChatTemplate->segments)->toBe(['Check Sonarr and Radarr for stuck downloads'])
        ->and($parsedChatTemplate->names())->toBe([])
        ->and($parsedChatTemplate->isValid())->toBeTrue();
});

test('tokens split the body and keep their parts in written order', function (): void {
    $parsedChatTemplate = parseChatTemplate('Check {{anime:id,title}} S{{season}}E{{episode}}');

    expect($parsedChatTemplate->isValid())->toBeTrue()
        ->and($parsedChatTemplate->segments[0])->toBe('Check ')
        ->and($parsedChatTemplate->segments[1])->toEqual(new ChatTemplateToken('anime', ['id', 'title'], '{{anime:id,title}}'))
        ->and($parsedChatTemplate->segments[2])->toBe(' S')
        ->and($parsedChatTemplate->segments[3])->toEqual(new ChatTemplateToken('season', [], '{{season}}'))
        ->and($parsedChatTemplate->names())->toBe(['anime', 'season', 'episode']);
});

test('a repeated name is one variable but every occurrence is a token', function (): void {
    $parsedChatTemplate = parseChatTemplate('{{anime:title}} again: {{anime:id}}');

    expect($parsedChatTemplate->names())->toBe(['anime'])
        ->and($parsedChatTemplate->tokens())->toHaveCount(2)
        ->and($parsedChatTemplate->tokens()[1]->parts)->toBe(['id']);
});

test('multibyte literal text is preserved around tokens', function (): void {
    $parsedChatTemplate = parseChatTemplate('Frieren — {{x}} ✓');

    expect($parsedChatTemplate->segments)->toEqual(['Frieren — ', new ChatTemplateToken('x', [], '{{x}}'), ' ✓']);
});

test('malformed placeholders are reported and kept as literal text', function (string $body, string $messageFragment): void {
    $parsedChatTemplate = parseChatTemplate($body);

    expect($parsedChatTemplate->isValid())->toBeFalse()
        ->and($parsedChatTemplate->tokens())->toBe([])
        ->and(implode(' ', $parsedChatTemplate->errors))->toContain($messageFragment)
        ->and(implode('', $parsedChatTemplate->segments))->toBe($body);
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
