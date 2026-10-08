<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Concerns\AiModelSelectionValidationRules;
use App\Concerns\ChatAttachmentValidationRules;
use App\Enums\AiMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SendChatRequest extends FormRequest
{
    use AiModelSelectionValidationRules;
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
            'override' => ['nullable', 'array:provider,model,reasoning'],
            ...$this->selectionRules('override'),
        ];
    }

    /**
     * A new conversation's requested override must name a priced model of
     * its provider. Ignored for an existing conversation, which keeps its own.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validatePricedSelection(
            $validator,
            'override',
            $this->input('override.provider'),
            $this->input('override.model'),
        )];
    }
}
