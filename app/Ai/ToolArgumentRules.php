<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Rules\RejectEmptyString;
use Laravel\Ai\Contracts\Tool;

/**
 * Validation rules derived from a tool's own schema(), so the arguments the
 * model sends are checked against the exact shape it was shown (types, enums,
 * required keys, nested items) before execute() runs.
 *
 * Two deliberate relaxations of Laravel's JsonSchema::rules() mapping, because
 * the tools are not sent in provider strict mode and execute() already reads
 * optional arguments with `?? null`:
 *  - a required-but-nullable field may be omitted (absent counts as null);
 *  - an empty string on an optional named field counts as omitted instead of
 *    being refused. Array items (`foo.*`) still refuse an empty string.
 */
final class ToolArgumentRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function for(Tool $tool): array
    {
        $properties = $tool->schema(new JsonSchemaTypeFactory);

        if ($properties === []) {
            return [];
        }

        $rules = JsonSchema::rules(JsonSchema::object($properties));

        foreach ($rules as $attribute => $attributeRules) {
            $rules[$attribute] = array_values(array_filter(
                $attributeRules,
                static fn (mixed $rule): bool => $rule !== 'present'
                    && (! $rule instanceof RejectEmptyString || str_ends_with($attribute, '.*')),
            ));
        }

        return $rules;
    }
}
