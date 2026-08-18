<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\NotImplementedYetException;
use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
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

it('publishes a configuration whose default allows no host', function (): void {
    expect(config('lara-spec-first.remote_references.allowed_hosts'))->toBe([]);
});

// Null rather than a path: the artifact is written beside the specification
// unless an application says otherwise, and picking a directory here would mean
// picking one before knowing where the specification lives.
it('defaults the artifact to no configured path of its own', function (): void {
    expect(config('lara-spec-first.artifact.path'))->toBeNull();
});

// The guard is resolved with whatever the application configured, which is the
// only reason the setting is worth having at all.
it('builds the remote reference guard from the configuration', function (): void {
    config()->set('lara-spec-first.remote_references.allowed_hosts', ['schemas.example.com']);

    expect(fn () => app(RemoteReferenceGuard::class)->assertNoRemoteReferences([]))
        ->toThrow(NotImplementedYetException::class);
});
