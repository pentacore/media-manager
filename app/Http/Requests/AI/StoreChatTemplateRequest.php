<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Concerns\ChatTemplateValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatTemplateRequest extends FormRequest
{
    use ChatTemplateValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->chatTemplateRules();
    }
}
