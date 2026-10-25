<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\DecisionEventValidationRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewDecisionPromptRequest extends FormRequest
{
    use DecisionEventValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->decisionEventRules('', true);
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkDecisionPayloadObject($validator, 'payload_json');
            },
        ];
    }
}
