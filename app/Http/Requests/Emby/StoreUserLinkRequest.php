<?php

declare(strict_types=1);

namespace App\Http\Requests\Emby;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emby_username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
