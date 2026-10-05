<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * The one grammar for chat template bodies: {{name}} or {{name:part,part}}.
 * Save validation, rendering and the live preview all parse through here so
 * they can never disagree about what a body means.
 */
final class ChatTemplateParser
{
    /** Parts a series/movie token may request. */
    public const array LIBRARY_PARTS = ['title', 'year', 'id'];

    private const string TOKEN_PATTERN = '/\{\{(.*?)\}\}/s';

    private const string INNER_PATTERN = '/^(?<name>[a-z][a-z0-9_]{0,31})(?::(?<parts>[a-z]+(?:,[a-z]+)*))?$/';

    public function parse(string $body): ParsedChatTemplate
    {
        $segments = [];
        $errors = [];
        $offset = 0;

        preg_match_all(self::TOKEN_PATTERN, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$raw, $position] = $match[0];

            $this->appendLiteral($segments, $errors, substr($body, $offset, $position - $offset));
            $offset = $position + strlen($raw);

            $token = $this->token($raw, $match[1][0], $errors);
            $segments[] = $token ?? $raw;
        }

        $this->appendLiteral($segments, $errors, substr($body, $offset));

        return new ParsedChatTemplate($segments, array_values(array_unique($errors)));
    }

    /**
     * @param  list<string|ChatTemplateToken>  $segments
     * @param  list<string>  $errors
     */
    private function appendLiteral(array &$segments, array &$errors, string $literal): void
    {
        if ($literal === '') {
            return;
        }

        if (str_contains($literal, '{{') || str_contains($literal, '}}')) {
            $errors[] = 'A "{{" or "}}" is not part of a complete placeholder. Use {{name}} or {{name:title,year,id}}.';
        }

        $segments[] = $literal;
    }

    /**
     * @param  list<string>  $errors
     */
    private function token(string $raw, string $inner, array &$errors): ?ChatTemplateToken
    {
        if (preg_match(self::INNER_PATTERN, $inner, $groups) !== 1) {
            $errors[] = sprintf('%s is not a valid placeholder. Use {{name}} or {{name:title,year,id}} with a lowercase name.', $raw);

            return null;
        }

        $parts = ($groups['parts'] ?? '') === '' ? [] : explode(',', $groups['parts']);
        $unknown = array_values(array_diff($parts, self::LIBRARY_PARTS));

        if ($unknown !== []) {
            $errors[] = sprintf('%s uses an unknown part "%s". Parts are title, year and id.', $raw, $unknown[0]);

            return null;
        }

        if (count($parts) !== count(array_unique($parts))) {
            $errors[] = sprintf('%s repeats a part.', $raw);

            return null;
        }

        return new ChatTemplateToken($groups['name'], $parts, $raw);
    }
}
