<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\PinnedConnectionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteManualImportRequest extends FormRequest
{
    use PinnedConnectionValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only the download id the dialog showed; the candidates are
            // re-fetched server-side so no path or foreign key is trusted.
            'download_id' => ['required', 'string', 'max:255'],
            // Paths the user unticked in the dialog; they only ever remove
            // re-fetched candidates, never add one.
            'excluded_paths' => ['nullable', 'array', 'max:200'],
            'excluded_paths.*' => ['string', 'max:1024'],
            ...$this->pinnedConnectionRules(),
        ];
    }
}
