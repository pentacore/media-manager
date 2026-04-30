<?php

declare(strict_types=1);

use App\Providers\AIServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AIServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
