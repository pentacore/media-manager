<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use Illuminate\Validation\Rule;

enum HostCheckType: string
{
    use EnumUtils;

    case MountPresent = 'mount_present';
    case PathOnMount = 'path_on_mount';
    case FileFreshness = 'file_freshness';
    case DbPing = 'db_ping';
    case MountpointProtected = 'mountpoint_protected';

    public function label(): string
    {
        return match ($this) {
            self::MountPresent => 'Mount present',
            self::PathOnMount => 'Path on mount',
            self::FileFreshness => 'File freshness',
            self::DbPing => 'Database ping',
            self::MountpointProtected => 'Mountpoint protected',
        };
    }

    /**
     * Validation rules for this type's params, keyed by the bare param name.
     * Callers prefix the keys with `params.`. The params go to an agent running as root,
     * so every string is single-line: no control characters, NUL or newlines.
     *
     * @return array<string, list<mixed>>
     */
    public function paramRules(): array
    {
        $absolutePath = ['required', 'string', 'max:1024', 'regex:/\A\/[^\x00-\x1F\x7F]*\z/'];

        return match ($this) {
            self::MountPresent => [
                'path' => $absolutePath,
                'fstype' => ['nullable', 'string', 'max:32', 'regex:/\A[a-z0-9._-]+\z/'],
                'source' => ['nullable', 'string', 'max:1024', 'regex:/\A[^\x00-\x1F\x7F]+\z/'],
            ],
            self::PathOnMount => [
                'path' => $absolutePath,
                'mount' => $absolutePath,
            ],
            self::FileFreshness => [
                'path' => $absolutePath,
                // A file-name glob: the agent matches entries directly in `path`, never below it.
                'pattern' => ['nullable', 'string', 'max:255', 'regex:/\A[^\/\x00-\x1F\x7F]+\z/'],
                'warn_hours' => ['required', 'numeric', 'min:0.1', 'max:8760'],
                'crit_hours' => ['required', 'numeric', 'min:0.1', 'max:8760', 'gte:params.warn_hours'],
            ],
            self::DbPing => [
                'engine' => ['required', DbPingEngine::validationRule()],
                'host' => ['required', 'string', 'max:253', Rule::anyOf([
                    ['ip'],
                    ['regex:/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/'],
                ])],
                'port' => ['required', 'integer', 'between:1,65535'],
                'timeout_seconds' => ['nullable', 'integer', 'between:1,60'],
            ],
            self::MountpointProtected => [
                'path' => $absolutePath,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function paramKeys(): array
    {
        return array_keys($this->paramRules());
    }

    /**
     * What the check looks at: its path, or host:port for a database ping.
     *
     * @param  array<string, mixed>  $params
     */
    public function subject(array $params): string
    {
        return match ($this) {
            self::DbPing => sprintf('%s:%s', $params['host'] ?? '', $params['port'] ?? ''),
            default => (string) ($params['path'] ?? ''),
        };
    }
}
