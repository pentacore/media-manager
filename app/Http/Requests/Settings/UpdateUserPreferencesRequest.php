<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\UserPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_format' => ['required', Rule::in(UserPreferences::TIME_FORMATS)],
            'date_format' => ['required', Rule::in(UserPreferences::DATE_FORMATS)],
            'timezone' => ['required', 'string', Rule::in(UserPreferences::availableTimezones())],
            'first_day_of_week' => ['required', 'integer', Rule::in(UserPreferences::WEEK_STARTS)],
            'show_relative_time' => ['required', 'boolean'],
        ];
    }
}
