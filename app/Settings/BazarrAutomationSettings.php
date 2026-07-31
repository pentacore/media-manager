<?php

declare(strict_types=1);

namespace App\Settings;

final readonly class BazarrAutomationSettings
{
    private const string SETTINGS_KEY = 'bazarr.automation';

    private const array DEFAULTS = [
        'enabled' => false,
        'reconciliation_interval_minutes' => 15,
        'grace_hours' => ['anime' => 24, 'tv' => 72, 'movie' => 72],
        'probe_spacing_hours' => 24,
        'empty_probe_threshold' => 2,
        'max_cases_per_cycle' => 100,
        'max_probes_per_cycle' => 10,
        'max_advisor_escalations_per_cycle' => 3,
        'advisor_concurrency' => 1,
        'upload_max_kilobytes' => 5120,
        'upload_expiry_hours' => 24,
    ];

    public function __construct(private AppSettings $appSettings) {}

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $stored = $this->appSettings->get(self::SETTINGS_KEY, []);

        return $this->normalize(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $this->appSettings->set(self::SETTINGS_KEY, $this->normalize($configuration));
    }

    public function enabled(): bool
    {
        return $this->configuration()['enabled'];
    }

    public function reconciliationIntervalMinutes(): int
    {
        return $this->configuration()['reconciliation_interval_minutes'];
    }

    public function graceHours(string $scope): int
    {
        return $this->configuration()['grace_hours'][$scope] ?? self::DEFAULTS['grace_hours']['tv'];
    }

    public function probeSpacingHours(): int
    {
        return $this->configuration()['probe_spacing_hours'];
    }

    public function emptyProbeThreshold(): int
    {
        return $this->configuration()['empty_probe_threshold'];
    }

    public function maxCasesPerCycle(): int
    {
        return $this->configuration()['max_cases_per_cycle'];
    }

    public function maxProbesPerCycle(): int
    {
        return $this->configuration()['max_probes_per_cycle'];
    }

    public function maxAdvisorEscalationsPerCycle(): int
    {
        return $this->configuration()['max_advisor_escalations_per_cycle'];
    }

    public function advisorConcurrency(): int
    {
        return $this->configuration()['advisor_concurrency'];
    }

    public function uploadMaxKilobytes(): int
    {
        return $this->configuration()['upload_max_kilobytes'];
    }

    public function uploadExpiryHours(): int
    {
        return $this->configuration()['upload_expiry_hours'];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function normalize(array $configuration): array
    {
        $normalized = self::DEFAULTS;
        $normalized['enabled'] = is_bool($configuration['enabled'] ?? null)
            ? $configuration['enabled']
            : self::DEFAULTS['enabled'];
        $normalized['reconciliation_interval_minutes'] = $this->bounded($configuration['reconciliation_interval_minutes'] ?? null, 5, 1440, 15);
        $normalized['probe_spacing_hours'] = $this->bounded($configuration['probe_spacing_hours'] ?? null, 1, 720, 24);
        $normalized['empty_probe_threshold'] = $this->bounded($configuration['empty_probe_threshold'] ?? null, 2, 10, 2);
        $normalized['max_cases_per_cycle'] = $this->bounded($configuration['max_cases_per_cycle'] ?? null, 1, 1000, 100);
        $normalized['max_probes_per_cycle'] = $this->bounded($configuration['max_probes_per_cycle'] ?? null, 1, 100, 10);
        $normalized['max_advisor_escalations_per_cycle'] = $this->bounded($configuration['max_advisor_escalations_per_cycle'] ?? null, 0, 25, 3);
        $normalized['advisor_concurrency'] = $this->bounded($configuration['advisor_concurrency'] ?? null, 1, 5, 1);
        $normalized['upload_max_kilobytes'] = $this->bounded($configuration['upload_max_kilobytes'] ?? null, 64, 10240, 5120);
        $normalized['upload_expiry_hours'] = $this->bounded($configuration['upload_expiry_hours'] ?? null, 1, 168, 24);
        $graceHours = is_array($configuration['grace_hours'] ?? null) ? $configuration['grace_hours'] : [];

        foreach (['anime' => 24, 'tv' => 72, 'movie' => 72] as $scope => $fallback) {
            $normalized['grace_hours'][$scope] = $this->bounded($graceHours[$scope] ?? null, 1, 8760, $fallback);
        }

        return $normalized;
    }

    private function bounded(mixed $value, int $minimum, int $maximum, int $fallback): int
    {
        if (! is_int($value)) {
            return $fallback;
        }

        return max($minimum, min($maximum, $value));
    }
}
