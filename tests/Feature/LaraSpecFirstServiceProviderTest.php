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

// The guard is resolved with whatever the application configured, which is the
// only reason the setting is worth having at all.
it('builds the remote reference guard from the configuration', function (): void {
    config()->set('lara-spec-first.remote_references.allowed_hosts', ['schemas.example.com']);

    expect(fn () => app(RemoteReferenceGuard::class)->assertNoRemoteReferences([]))
        ->toThrow(NotImplementedYetException::class);
});

// Every setting below belongs to a feature that is documented and decided but
// not built. Nothing reads them yet, so what is worth pinning is the default —
// it is public API surface the moment the package ships, and a default that
// drifts silently is how a consumer's configuration stops meaning what it said.

it('defaults the generated tree to a path and namespace that agree', function (): void {
    expect(config('lara-spec-first.generated.path'))->toBe('app/Http/Generated')
        ->and(config('lara-spec-first.generated.namespace'))->toBe('App\\Http\\Generated');
});

// Empty rather than a guess: scanning the whole application would touch every
// autoloaded class, vendor included, on every build.
it('scans nothing for overrides until a project names a directory', function (): void {
    expect(config('lara-spec-first.overrides.scan'))->toBe([]);
});

// The disk is the switch, so a null disk publishes nothing no matter what the
// path says. The keep list is the inverse of a strip list on purpose: an
// extension nobody named is dropped rather than published by omission, and
// `x-audience` is absent because internal operations are removed outright.
it('publishes no sanitized specification by default', function (): void {
    expect(config('lara-spec-first.publish.disk'))->toBeNull()
        ->and(config('lara-spec-first.publish.keep_extensions'))
        ->toBe(['x-lifecycle', 'x-sunset']);
});

// Five keys that are exactly as much public API surface as the rate limit
// mapping beside them, and nothing else pins them.
it('defaults the pagination mapping to Laravel\'s own envelope', function (): void {
    expect(config('lara-spec-first.pagination.mapping'))->toBe([
        'page' => 'page',
        'size' => 'per_page',
        'collection' => 'data',
        'total' => 'meta.total',
        'last_page' => 'meta.last_page',
    ]);
});

// A null driver means the feature does nothing at all, which is the only honest
// default for a convention this package cannot detect.
it('selects no driver for the features OpenAPI never standardized', function (string $feature): void {
    expect(config("lara-spec-first.{$feature}.driver"))->toBeNull();
})->with(['pagination', 'rate_limiting']);

// One window, written without a name. Several are named to tell them apart, and
// mixing the two levels is an error rather than a guess.
it('defaults a rate limit mapping to a single unnamed window', function (): void {
    expect(config('lara-spec-first.rate_limiting.mapping'))->toBe([
        'limit' => 'RateLimit-Limit',
        'remaining' => 'RateLimit-Remaining',
        'reset' => 'RateLimit-Reset',
    ]);
});
