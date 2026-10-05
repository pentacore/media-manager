<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\ChatTemplateVariableType;
use App\Models\ChatTemplate;
use App\Models\User;
use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Value rules are built from the bound template's current variables, so a
 * template edited after the fill dialog opened asks for its new fields.
 * Library existence is the renderer's job.
 */
class RenderChatTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        abort_unless($user instanceof User && $this->chatTemplate()->isOwnedBy($user), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['values' => ['present', 'array']];

        foreach ($this->chatTemplate()->variables as $variable) {
            $rules[sprintf('values.%s', $variable['name'])] = match (ChatTemplateVariableType::from($variable['type'])) {
                ChatTemplateVariableType::Text => ['required', 'string', 'max:500'],
                ChatTemplateVariableType::Number => ['required', 'integer', sprintf('min:%d', ChatTemplateRenderer::MIN_NUMBER), sprintf('max:%d', ChatTemplateRenderer::MAX_NUMBER)],
                ChatTemplateVariableType::Choice => ['required', 'string', Rule::in($variable['options'] ?? [])],
                ChatTemplateVariableType::Series, ChatTemplateVariableType::Movie => ['required', 'integer', 'min:1'],
            };
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->chatTemplate()->variables as $variable) {
            $attributes[sprintf('values.%s', $variable['name'])] = $variable['label'];
        }

        return $attributes;
    }

    private function chatTemplate(): ChatTemplate
    {
        $chatTemplate = $this->route('chatTemplate');

        abort_unless($chatTemplate instanceof ChatTemplate, 404);

        return $chatTemplate;
    }
}
