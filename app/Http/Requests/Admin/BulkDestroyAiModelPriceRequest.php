<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelPriceSelectionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyAiModelPriceRequest extends FormRequest
{
    use AiModelPriceSelectionValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->aiModelPriceSelectionRules();
    }
}
