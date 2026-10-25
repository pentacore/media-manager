<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Override;

/**
 * The re-judge dialog's live estimate: no cap yet.
 */
class EstimateNeutralRejudgeRequest extends StartNeutralRejudgeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    #[Override]
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['cost_cap_usd']);

        return $rules;
    }
}
