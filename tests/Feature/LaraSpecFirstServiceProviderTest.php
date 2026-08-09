<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Illuminate\Routing\Route;

it('is loaded into the application', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(LaraSpecFirstServiceProvider::class);
});

it('registers no routes of its own yet', function () {
    // Contract-driven routing lands in a later change; until then the provider
    // must stay inert.
    //
    // Asserting that the router is globally empty would test the framework
    // rather than this package: Testbench registers routes of its own, and how
    // many depends on the Laravel version. Filter to routes this package owns.
    $ours = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_contains($route->getActionName(), 'Gcob\\LaraSpecFirst'));

    expect($ours)->toBeEmpty();
});
