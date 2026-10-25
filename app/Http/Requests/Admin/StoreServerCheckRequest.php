<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\HostCheckType;
use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * Adds a check, and (with `type` forced to the stored one) edits it: a check's type never changes after it is created.
 * The params' keys depend on the type (`HostCheckType::paramRules()`).
 */
#[TypefinderOverrides([
    'params' => 'Record<string, string | number | null>',
])]
class StoreServerCheckRequest extends FormRequest
{
    /**
     * A check of another server answers 404 before its params are validated.
     */
    public function authorize(): bool
    {
        $serverCheck = $this->route('serverCheck');
        $server = $this->route('server');

        abort_if($serverCheck instanceof ServerCheck && (! $server instanceof Server || $serverCheck->server_id !== $server->id), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('type');
        $hostCheckType = is_string($type) ? HostCheckType::tryFrom($type) : null;
        $paramRules = [];

        foreach ($hostCheckType?->paramRules() ?? [] as $name => $rules) {
            $paramRules[sprintf('params.%s', $name)] = $rules;
        }

        return [
            'type' => ['required', HostCheckType::validationRule()],
            'is_enabled' => ['sometimes', 'boolean'],
            'params' => ['required', 'array'],
            ...$paramRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'params.path' => 'path',
            'params.mount' => 'mount',
            'params.fstype' => 'filesystem type',
            'params.source' => 'source',
            'params.pattern' => 'pattern',
            'params.warn_hours' => 'warn hours',
            'params.crit_hours' => 'critical hours',
            'params.engine' => 'engine',
            'params.host' => 'host',
            'params.port' => 'port',
            'params.timeout_seconds' => 'timeout',
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'params.path.regex' => 'The path must start with / and fit on one line.',
            'params.mount.regex' => 'The mount must start with / and fit on one line.',
            'params.source.regex' => 'The source must fit on one line.',
            'params.pattern.regex' => 'The pattern is a file name glob on one line, without / and with every [ closed by a ].',
            'params.crit_hours.gte' => 'The critical hours must be at least the warn hours.',
        ];
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $serverCheck = $this->route('serverCheck');

        if ($serverCheck instanceof ServerCheck) {
            $this->merge(['type' => $serverCheck->type->value]);
        }
    }
}
