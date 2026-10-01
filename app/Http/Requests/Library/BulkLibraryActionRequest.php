<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\BulkActionValidationRules;
use App\Concerns\MediaActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class BulkLibraryActionRequest extends FormRequest
{
    use BulkActionValidationRules;
    use MediaActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->mediaActionTargetRules(),
            ...$this->bulkIdRules(),
            ...$this->libraryBulkRules(),
        ];
    }
}
