<?php

declare(strict_types=1);

namespace App\Http\Requests\Sabnzbd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetSpeedLimitRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A percentage of SABnzbd's maximum (1-100), a positive rate with a
            // K or M suffix (500K, 5M, 2.5M), or empty (null) for no limit.
            'value' => ['present', 'nullable', 'string', 'max:12', 'regex:/^(?:100|[1-9][0-9]?|[1-9][0-9]{0,5}(?:\.[0-9]{1,2})?[KkMm])$/'],
        ];
    }
}
