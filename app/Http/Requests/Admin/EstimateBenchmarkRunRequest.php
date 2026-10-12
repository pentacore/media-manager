<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Override;

/**
 * The setup page's live estimate: a run setup before it has a name or an
 * estimate to confirm.
 */
class EstimateBenchmarkRunRequest extends StoreBenchmarkRunRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    #[Override]
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['confirm_estimate_usd']);
        $rules['name'] = ['nullable', 'string', 'max:160'];

        return $rules;
    }
}
