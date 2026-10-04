<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteManualImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only the download id the dialog showed; the candidates are
            // re-fetched server-side so no path or foreign key is trusted.
            'download_id' => ['required', 'string', 'max:255'],
            // The connection the queue row was rendered from.
            'service_connection_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
