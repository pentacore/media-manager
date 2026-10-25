<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
class EnrollAgentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enrollment_token' => ['required', 'string', 'size:32', 'alpha_num:ascii'],
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'kernel' => ['nullable', 'string', 'max:255'],
            'arch' => ['nullable', 'string', 'max:32'],
            'agent_version' => ['required', 'string', 'max:64'],
        ];
    }
}
