<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ToggleServerCheckRequest extends FormRequest
{
    /**
     * A check of another server answers 404 before the body is validated.
     */
    public function authorize(): bool
    {
        $serverCheck = $this->route('serverCheck');
        $server = $this->route('server');

        abort_unless($serverCheck instanceof ServerCheck && $server instanceof Server && $serverCheck->server_id === $server->id, 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
