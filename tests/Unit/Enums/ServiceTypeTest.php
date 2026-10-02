<?php

declare(strict_types=1);

use App\Enums\ServiceType;

test('every service but Bazarr uses the generic webhook endpoint', function (): void {
    expect(ServiceType::genericWebhookValues())
        ->toBe(['sonarr', 'radarr', 'emby', 'seerr', 'prowlarr', 'sabnzbd', 'whisparr'])
        ->and(ServiceType::Bazarr->usesGenericWebhook())->toBeFalse()
        ->and(ServiceType::Sonarr->usesGenericWebhook())->toBeTrue();
});
