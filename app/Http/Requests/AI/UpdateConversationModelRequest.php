<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Concerns\AiModelSelectionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateConversationModelRequest extends FormRequest
{
    use AiModelSelectionValidationRules;

    /**
     * Route middleware authorises the request; the controller checks that
     * the conversation belongs to the user, as rename does.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is sent, null meaning "back to the admin default".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_map(
            static fn (array $rules): array => ['present', ...$rules],
            $this->selectionRules(''),
        );
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validatePricedSelection(
            $validator,
            '',
            $this->input('provider'),
            $this->input('model'),
        )];
    }
}
