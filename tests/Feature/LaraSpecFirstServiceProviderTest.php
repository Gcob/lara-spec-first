<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;

it('is loaded into the application', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(LaraSpecFirstServiceProvider::class);
});

it('boots without registering any route yet', function () {
    // Contract-driven routing lands in a later change. Until then the provider
    // must stay inert: booting it should not add routes of its own.
    expect(app('router')->getRoutes()->getRoutes())->toBeEmpty();
});
