<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Gcob\LaraSpecFirst\Parsing\Exceptions\MissingVendoredReferenceException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

it('is loaded into the application', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(LaraSpecFirstServiceProvider::class);
});

// The fresh-clone state, and the one that must not throw: .gitignore decides
// what a project commits, and `spec:build` is a command of this package — a
// provider that refused to boot without a generated tree would make the
// application unbootable exactly when the command that writes one needs to run.
//
// Pointed at a directory that cannot exist rather than reading whatever happens
// to be at the default location, so the assertion depends on nothing another
// test file may have written there.
it('registers nothing when no generated routes file exists', function (): void {
    config()->set(GeneratedRoutesLocator::SETTING, 'does/not/exist');

    $before = count(app('router')->getRoutes()->getRoutes());

    (new LaraSpecFirstServiceProvider(app()))->boot();

    expect(app('router')->getRoutes()->getRoutes())->toHaveCount($before);
});

// The default is relative to the application root, so what the provider looks
// for on a stock installation is one predictable file. Path resolution only:
// whether anything is at that path is another test's subject, and asserting it
// here would couple this file to what the routing tests write.
it('looks for its routes in the generated tree the configuration names', function (): void {
    $locator = new GeneratedRoutesLocator(base_path(), config()->string(GeneratedRoutesLocator::SETTING));

    expect($locator->path())->toBe(base_path('app/Http/Generated/routes.php'));
});

// The console is the way out of a broken configuration, so the one place the
// refusal must not fire is the one place it could strip you of `config:clear`,
// `spec:build` and `spec:doctor` at once. The refusal itself is a unit test,
// where the branch is reachable without a booted application.
it('lets the console through a setting it would refuse a request on', function (): void {
    config()->set(GeneratedRoutesLocator::SETTING, '');

    expect(fn () => (new LaraSpecFirstServiceProvider(app()))->boot())
        ->not->toThrow(UnusableSettingException::class);
});

it('publishes a configuration whose default allows no host', function (): void {
    expect(config('lara-spec-first.remote_references.allowed_hosts'))->toBe([]);
});

it('defaults the vendor path to a directory at the project root', function (): void {
    expect(config('lara-spec-first.remote_references.vendor_path'))->toBe('openapi-external-refs');
});

// The guard is resolved with whatever the application configured, which is the
// only reason either setting is worth having at all. An allowed host with no
// vendored copy refuses rather than fetching: the container binding never
// passes `--update-refs`, since fetching has exactly one entry point —
// `spec:build`'s own flag — and the container is not it.
it('builds the remote reference guard from the configured allowlist and vendor root', function (): void {
    config()->set('lara-spec-first.remote_references.allowed_hosts', ['schemas.example.com']);

    $result = app(RemoteReferenceGuard::class)->resolve(
        ['$ref' => 'https://schemas.example.com/common.yaml'],
        base_path(),
    );

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(MissingVendoredReferenceException::class)
        ->and($result->faults[0]->getMessage())->toContain('openapi-external-refs/schemas.example.com/common.yaml');
});

// `?? 'openapi-external-refs'` only catches a missing or null key: an
// application that publishes an empty string would otherwise fall through to
// `$app->basePath('')`, the application root itself, and every vendored copy
// would land there uncontained instead of raising the way an unusable setting
// always does elsewhere.
it('refuses an empty vendor path rather than silently vendoring into the project root', function (): void {
    config()->set('lara-spec-first.remote_references.vendor_path', '');

    expect(fn () => app(RemoteReferenceGuard::class))
        ->toThrow(UnusableSettingException::class, 'lara-spec-first.remote_references.vendor_path');
});

// The one config key the build actually reads today, so its default is behavior
// rather than only a promise. One root document rather than a list: multi-file
// contracts are written as local `$ref`s from it, and widening this to a list
// later is non-breaking where narrowing it would not be.
it('reads one root specification, named by default at the project root', function (): void {
    expect(config('lara-spec-first.spec.path'))->toBe('openapi.yaml');
});

// Every setting below belongs to a feature that is documented and decided but
// not built, with one exception noted where it applies. Nothing reads them yet,
// so what is worth pinning is the default — it is public API surface the moment
// the package ships, and a default that drifts silently is how a consumer's
// configuration stops meaning what it said.

// The exception: `generated.path` is read at boot, so its default is behavior
// rather than only a promise. `generated.namespace` is still only a promise,
// because nothing emits a class into it yet.
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
