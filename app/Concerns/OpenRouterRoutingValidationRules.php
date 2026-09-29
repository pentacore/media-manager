<?php

declare(strict_types=1);

namespace App\Concerns;

trait OpenRouterRoutingValidationRules
{
    /**
     * A comma-separated list of OpenRouter upstream provider slugs (see
     * `OpenRouterSettings::normalizeSlugs()`): lowercase letters and digits,
     * with `-`, `.` and `/` allowed inside a segment but not at either end,
     * up to 64 characters per segment. Surrounding whitespace, mixed case
     * and blank segments (`"a,, b"`) are all allowed here; normalization
     * trims, lowercases and drops blanks once validation has passed.
     *
     * @return list<string>
     */
    protected function openRouterSlugListRules(): array
    {
        return [
            'nullable',
            'string',
            'max:500',
            'regex:/^\s*([a-z0-9](?:[a-z0-9\-.\/]{0,62}[a-z0-9])?)?\s*(,\s*([a-z0-9](?:[a-z0-9\-.\/]{0,62}[a-z0-9])?)?\s*)*$/i',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function openRouterSlugListMessages(): array
    {
        $message = 'Use comma-separated OpenRouter provider slugs, e.g. anthropic, amazon-bedrock.';

        return [
            'openrouter_order.regex' => $message,
            'openrouter_ignore.regex' => $message,
        ];
    }
}
