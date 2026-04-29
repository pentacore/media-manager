<?php

declare(strict_types=1);

namespace App\Http\Requests\Sabnzbd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangePriorityRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'integer', 'in:-1,0,1,2'],
        ];
    }
}
