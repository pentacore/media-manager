<?php

declare(strict_types=1);

namespace App\Http\Requests\Whisparr;

use App\Concerns\BulkActionValidationRules;
use App\Concerns\WhisparrActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class BulkWhisparrActionRequest extends FormRequest
{
    use BulkActionValidationRules;
    use WhisparrActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->whisparrTargetRules(),
            ...$this->bulkIdRules(),
            ...$this->libraryBulkRules(),
        ];
    }
}
