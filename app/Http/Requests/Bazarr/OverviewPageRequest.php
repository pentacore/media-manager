<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use App\Concerns\BazarrPageValidationRules;
use Illuminate\Foundation\Http\FormRequest;

final class OverviewPageRequest extends FormRequest
{
    use BazarrPageValidationRules;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->bazarrPageRules();
    }
}
