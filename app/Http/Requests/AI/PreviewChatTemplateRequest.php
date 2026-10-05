<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\ChatTemplatePreviewMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the request shape is validated: an in-progress draft is expected to
 * be wrong, and its problems come back in the preview's `errors` instead.
 */
class PreviewChatTemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:20000'],
            'variables' => ['present', 'array', 'max:50'],
            'variables.*' => ['array'],
            'mode' => ['nullable', 'string', ChatTemplatePreviewMode::validationRule()],
        ];
    }
}
