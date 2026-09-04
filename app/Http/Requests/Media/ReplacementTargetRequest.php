<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Concerns\ReplacementTargetValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ReplacementTargetRequest extends FormRequest
{
    use ReplacementTargetValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->replacementTargetRules(),
        ];
    }
}
