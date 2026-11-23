<?php

declare(strict_types=1);

/*
 * Merged over the package defaults (vendor/pentacore/laravel-typefinder
 * config/typefinder.php); set only what differs here.
 */
return [
    // resources/js/typefinder is committed so `typefinder:generate --check`
    // can gate drift (tests/Feature/GeneratedTypesTest.php). Without this the
    // generator re-adds the folder to .gitignore on every run.
    'gitignore_generated' => false,
];
