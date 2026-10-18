<?php

declare(strict_types=1);

namespace App\Http\Requests\Actions;

use App\Ai\Decision\SecondOpinionVerifier;
use App\Enums\SecondOpinionMode;
use App\Models\ActionTypeConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActionTypeConfigRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'requires_approval' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
            'second_opinion' => ['required', 'string', Rule::in($this->allowedSecondOpinionModes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'second_opinion.in' => 'This action type is never proposed automatically, so it takes no second opinion.',
        ];
    }

    /**
     * Only a type an automated proposer can produce takes a second opinion.
     *
     * @return list<string>
     */
    private function allowedSecondOpinionModes(): array
    {
        $actionTypeConfig = $this->route('actionTypeConfig');

        return $actionTypeConfig instanceof ActionTypeConfig && SecondOpinionVerifier::supportsActionType($actionTypeConfig->type)
            ? SecondOpinionMode::values()
            : [SecondOpinionMode::Off->value];
    }
}
