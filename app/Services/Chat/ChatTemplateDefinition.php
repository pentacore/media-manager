<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ChatTemplateVariableType;

/**
 * Checks that a template body and its variable settings agree, and shapes
 * validated settings for storage. Per-field shape rules (types, lengths,
 * name format) belong to the Form Request; this covers what needs the body
 * and the variables together. Also used by the live preview, so the editor
 * shows the same problems a save would.
 */
final readonly class ChatTemplateDefinition
{
    public function __construct(private ChatTemplateParser $chatTemplateParser) {}

    /**
     * @param  array<int, mixed>  $variables
     * @return array<string, list<string>>
     */
    public function errors(string $body, array $variables): array
    {
        $parsedChatTemplate = $this->chatTemplateParser->parse($body);
        $errors = [];

        foreach ($parsedChatTemplate->errors as $message) {
            $errors['body'][] = $message;
        }

        /** @var array<string, ChatTemplateVariableType|null> $configured */
        $configured = [];

        foreach (array_values($variables) as $index => $variable) {
            if (! is_array($variable)) {
                continue;
            }

            $name = is_string($variable['name'] ?? null) ? $variable['name'] : '';
            $type = ChatTemplateVariableType::tryFrom(is_string($variable['type'] ?? null) ? $variable['type'] : '');

            if (array_key_exists($name, $configured)) {
                $errors[sprintf('variables.%d.name', $index)][] = 'Another variable already uses this name.';

                continue;
            }

            $configured[$name] = $type;

            if (! in_array($name, $parsedChatTemplate->names(), true)) {
                $errors[sprintf('variables.%d.name', $index)][] = 'This variable is not used in the template.';
            }

            if ($type === null) {
                continue;
            }

            foreach ($this->settingErrors($type, $variable) as $field => $message) {
                $errors[sprintf('variables.%d.%s', $index, $field)][] = $message;
            }
        }

        foreach ($parsedChatTemplate->tokens() as $chatTemplateToken) {
            if (! array_key_exists($chatTemplateToken->name, $configured)) {
                $errors['body'][] = sprintf('%s has no variable settings.', $chatTemplateToken->raw);

                continue;
            }

            $type = $configured[$chatTemplateToken->name];

            if ($chatTemplateToken->parts !== [] && $type instanceof ChatTemplateVariableType && ! $type->isLibrary()) {
                $errors['body'][] = sprintf('%s uses parts, but only series and movie variables have parts.', $chatTemplateToken->raw);
            }
        }

        return array_map(static fn (array $messages): array => array_values(array_unique($messages)), $errors);
    }

    /**
     * @param  array<int, array<string, mixed>>  $variables  Already validated.
     * @return list<array{name: string, label: string, type: string, default: string|null, options: list<string>|null}>
     */
    public function normalize(array $variables): array
    {
        return array_values(array_map(function (array $variable): array {
            $chatTemplateVariableType = ChatTemplateVariableType::from((string) $variable['type']);

            return [
                'name' => (string) $variable['name'],
                'label' => trim((string) $variable['label']),
                'type' => $chatTemplateVariableType->value,
                'default' => $chatTemplateVariableType->supportsDefault() ? $this->cleanDefault($variable['default'] ?? null) : null,
                'options' => $chatTemplateVariableType->requiresOptions() ? $this->cleanOptions($variable['options'] ?? null) : null,
            ];
        }, $variables));
    }

    /**
     * @param  array<string, mixed>  $variable
     * @return array<string, string>
     */
    private function settingErrors(ChatTemplateVariableType $chatTemplateVariableType, array $variable): array
    {
        $errors = [];
        $options = $this->cleanOptions($variable['options'] ?? null);
        $default = $this->cleanDefault($variable['default'] ?? null);

        if ($chatTemplateVariableType->requiresOptions() && count($options) < 2) {
            $errors['options'] = 'Add at least two different options.';
        }

        if (! $chatTemplateVariableType->requiresOptions() && $options !== []) {
            $errors['options'] = 'Only choice variables have options.';
        }

        if ($default === null) {
            return $errors;
        }

        if (! $chatTemplateVariableType->supportsDefault()) {
            $errors['default'] = 'Series and movie variables cannot have a default.';
        } elseif ($chatTemplateVariableType === ChatTemplateVariableType::Number && filter_var($default, FILTER_VALIDATE_INT) === false) {
            $errors['default'] = 'The default must be a whole number.';
        } elseif ($chatTemplateVariableType->requiresOptions() && ! in_array($default, $options, true)) {
            $errors['default'] = 'The default must be one of the options.';
        }

        return $errors;
    }

    private function cleanDefault(mixed $default): ?string
    {
        if (! is_scalar($default)) {
            return null;
        }

        $trimmed = trim((string) $default);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function cleanOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $cleaned = [];

        foreach ($options as $option) {
            if (! is_scalar($option)) {
                continue;
            }

            $trimmed = trim((string) $option);

            if ($trimmed === '' || in_array($trimmed, $cleaned, true)) {
                continue;
            }

            $cleaned[] = $trimmed;
        }

        return $cleaned;
    }
}
