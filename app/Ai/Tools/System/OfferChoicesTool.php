<?php

declare(strict_types=1);

namespace App\Ai\Tools\System;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OfferChoicesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Show the user clickable buttons for a question with a fixed set of answers (which download, which release, which candidate, yes/no on a plan). Use it instead of listing options in text whenever the user must pick. Set multiple=true when they may pick several. After calling it, end your turn.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{choices: array{question: string, multiple: bool, options: list<array{label: string, description: string|null}>}, instruction: string}
     */
    protected function execute(Request $request): array
    {
        if (isset($request['options'])) {
            $request['options'] = $this->withTrimmedLabels($request['options']);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'options' => ['required', 'array', 'min:2', 'max:10'],
            'options.*.label' => ['required', 'string', 'max:80', 'distinct'],
            'options.*.description' => ['nullable', 'string', 'max:160'],
            'multiple' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{label: string, description?: string|null}> $options */
        $options = array_values($validated['options']);

        return [
            'choices' => [
                'question' => trim($validated['question']),
                'multiple' => (bool) ($validated['multiple'] ?? false),
                'options' => array_map(static fn (array $option): array => [
                    'label' => $option['label'],
                    'description' => filled($option['description'] ?? null) ? trim($option['description']) : null,
                ], $options),
            ],
            'instruction' => 'The options are shown to the user as buttons. End your turn now and wait for their answer; do not repeat the options as text.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('The question shown above the buttons.')->required(),
            'options' => $schema->array()->items($schema->object([
                'label' => $schema->string()->description('Button text, max 80 characters.')->required(),
                'description' => $schema->string()->description('Optional smaller line under the label, max 160 characters.')->nullable(),
            ]))->description('2 to 10 options.')->required(),
            'multiple' => $schema->boolean()->description('True when the user may pick several options.'),
        ];
    }

    /**
     * Trim each label before validation, so `distinct` compares the text the
     * buttons show rather than the raw argument.
     */
    private function withTrimmedLabels(mixed $options): mixed
    {
        if (! is_array($options)) {
            return $options;
        }

        return array_map(
            static fn (mixed $option): mixed => is_array($option) && is_string($option['label'] ?? null)
                ? [...$option, 'label' => trim($option['label'])]
                : $option,
            $options,
        );
    }
}
