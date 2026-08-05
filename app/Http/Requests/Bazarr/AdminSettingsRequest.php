<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class AdminSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->isAtLeast(UserRole::Admin) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'connection' => ['required', 'integer', 'min:1'],
            'settings' => ['required', 'array:scheduler_enabled,scheduler_interval_hours,automatic_subtitle_synchronization,use_postprocessing'],
            'settings.scheduler_enabled' => ['sometimes', 'required', 'boolean'],
            'settings.scheduler_interval_hours' => ['sometimes', 'required', 'integer', 'between:1,168'],
            'settings.automatic_subtitle_synchronization' => ['sometimes', 'required', 'boolean'],
            'settings.use_postprocessing' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
