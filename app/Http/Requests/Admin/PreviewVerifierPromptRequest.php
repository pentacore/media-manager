<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\VerifierCaseValidationRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewVerifierPromptRequest extends FormRequest
{
    use VerifierCaseValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->verifierCaseRules('', true);
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkVerifierPayloadObjects($validator, '');
            },
        ];
    }
}
