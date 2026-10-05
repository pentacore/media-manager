<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ChatTemplateVariableType;
use App\Models\ChatTemplate;
use App\Services\Chat\ChatTemplateDefinition;
use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared by the store/update chat template requests. Field shapes live in
 * the rules; the after() hook asks ChatTemplateDefinition whether the body
 * and the variable settings agree (once the shapes themselves are valid).
 */
trait ChatTemplateValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function chatTemplateRules(?ChatTemplate $chatTemplate = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('chat_templates', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($chatTemplate?->id),
            ],
            'body' => ['required', 'string', sprintf('max:%d', ChatTemplateRenderer::MAX_LENGTH)],
            'variables' => ['present', 'array', 'max:20'],
            'variables.*' => ['array'],
            'variables.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/'],
            'variables.*.label' => ['required', 'string', 'max:60'],
            'variables.*.type' => ['required', ChatTemplateVariableType::validationRule()],
            'variables.*.default' => ['nullable', 'string', 'max:500'],
            'variables.*.options' => ['nullable', 'array', 'max:20'],
            'variables.*.options.*' => ['nullable', 'string', 'max:100'],
            'auto_send' => ['required', 'boolean'],
            'pinned' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['body', 'variables', 'variables.*'])) {
                    return;
                }

                $variables = $this->input('variables');
                $errors = resolve(ChatTemplateDefinition::class)->errors(
                    (string) $this->input('body'),
                    is_array($variables) ? $variables : [],
                );

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }
}
