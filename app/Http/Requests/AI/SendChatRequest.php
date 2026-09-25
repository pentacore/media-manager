<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Concerns\ChatAttachmentValidationRules;
use App\Enums\AiMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendChatRequest extends FormRequest
{
    use ChatAttachmentValidationRules;

    /**
     * Route middleware (`role:admin`, `ai.enabled`) authorises the turn.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'workflow_id' => ['nullable', 'string', 'uuid'],
            'workflow_action' => ['nullable', 'string', 'in:approved,declined'],
            'mode' => ['nullable', 'string', AiMode::validationRule()],
            ...$this->chatAttachmentRules(),
        ];
    }
}
