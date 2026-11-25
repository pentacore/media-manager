<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|ConditionalRules|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', UserRole::validationRule()],
            'set_password' => ['boolean'],
            // Rule::when() keeps the password in the generated request type
            // (optional); an if around it fails `typefinder:generate --strict`
            // with no override to silence it (pentacore/Laravel-Typefinder#233).
            // The type still lacks password_confirmation
            // (pentacore/Laravel-Typefinder#232).
            'password' => Rule::when($this->boolean('set_password'), $this->passwordRules()),
        ];
    }
}
