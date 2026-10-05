<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\ChatTemplateVariableType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchChatTemplateLibraryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Deliberate two-case subset of ChatTemplateVariableType: only library types search.
            'type' => ['required', Rule::in([ChatTemplateVariableType::Series->value, ChatTemplateVariableType::Movie->value])],
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ];
    }
}
