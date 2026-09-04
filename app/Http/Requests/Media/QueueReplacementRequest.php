<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Concerns\ReplacementTargetValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class QueueReplacementRequest extends FormRequest
{
    use ReplacementTargetValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->replacementTargetRules(),
            'target_fingerprint' => ['required', 'string', 'size:64'],
            'candidate_fingerprint' => ['required', 'string', 'max:255'],
            'verify_subtitles' => ['required', 'boolean'],
        ];
    }
}
