<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers\ShowCurrentUserController;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers\ShowUserController;
use Gcob\LaraSpecFirst\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;

/*
 * The routes fixture is placed at the *default* configured location rather than
 * pointed at by an override, so that what these tests exercise is the path a
 * stock installation actually uses.
 *
 * It lands before the class runs rather than before each test, and that is the
 * whole reason this is `beforeAll`: the provider reads the file at boot, and by
 * the time a test body runs the application is already booted. Writing it once
 * up front means every test's own boot finds it, with nothing to refresh.
 *
 * Testbench's skeleton application is scratch space by design — `composer
 * clear` purges it — and everything written here is removed again below.
 */

/**
 * The directory a stock installation generates into.
 */
function generatedTree(): string
{
    return TestCase::applicationBasePath().'/app/Http/Generated';
}

beforeAll(function (): void {
    // The provider skips the generated routes entirely when the application's
    // routes are cached, which is its documented behaviour and this file's blind
    // spot: a developer who ran `testbench route:cache` by hand leaves a cache in
    // the skeleton, and every test here then asserts against routes nothing
    // loaded. Cleared rather than detected, because the fix is the same either way.
    @unlink(TestCase::applicationBasePath().'/bootstrap/cache/routes-v7.php');

    // Removed before as well as after. An `afterAll` does not run on a fatal
    // error or a Ctrl-C, and a leaked fixture at the default location is state
    // another test file would read as a generated tree.
    @unlink(generatedTree().'/'.GeneratedRoutesLocator::FILE);

    if (! is_dir(generatedTree())) {
        mkdir(generatedTree(), 0o777, true);
    }

    copy(
        dirname(__DIR__, 2).'/Fixtures/Generated/'.GeneratedRoutesLocator::FILE,
        generatedTree().'/'.GeneratedRoutesLocator::FILE,
    );
});

afterAll(function (): void {
    @unlink(generatedTree().'/'.GeneratedRoutesLocator::FILE);
    @rmdir(generatedTree());
});

/**
 * The routes the fixture declares, in the order the router holds them.
 *
 * @return list<Route>
 */
function generatedRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        static fn (Route $route): bool => str_contains(
            $route->getActionName(),
            'Tests\\Fixtures\\Generated\\Controllers',
        ),
    ));
}

it('registers the generated routes at boot', function (): void {
    expect(generatedRoutes())->toHaveCount(2);
});

// The specification's order is the route order, and Laravel resolves the first
// route registered. A build that sorted literal segments ahead of templated
// ones would be a heuristic a consumer cannot predict from their own document.
//
// See docs/guide/openapi-support.md — "Route order".
it('preserves the order the generated file declares', function (): void {
    expect(array_map(fn (Route $route): string => $route->uri(), generatedRoutes()))
        ->toBe(['users/me', 'users/{id}']);
});

// The whole point of that order: two routes match `GET /users/me`, and the one
// written first is the one that answers.
//
// Driven through the HTTP kernel rather than the test harness's own `get()`
// helper, because the assertion is about which controller answered and the
// exact body says that where `assertSee` only says the body contains it.
it('lets the first matching route win', function (string $uri, string $answer): void {
    $response = app(HttpKernel::class)->handle(Request::create($uri));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe($answer);
})->with([
    ['/users/me', 'show-current-user'],
    ['/users/42', 'show-user:42'],
]);

// Not a style preference: an action that is anything but a pair of strings is
// what stops `route:cache` from producing a file the application can load.
it('points every route at a controller by name rather than at a closure', function (): void {
    expect(array_map(fn (Route $route): string => $route->getActionName(), generatedRoutes()))
        ->toBe([
            ShowCurrentUserController::class.'@routeAction',
            ShowUserController::class.'@routeAction',
        ]);

    foreach (generatedRoutes() as $route) {
        expect($route->getAction('uses'))->toBeString();
    }
});

/*
 * `route:cache` splits into two claims, and keeping them apart is what stopped
 * this file from passing for the wrong reason.
 *
 * The command boots a *fresh* application from the skeleton's bootstrap/app.php,
 * which reads testbench.yaml — not this test's configuration. So which routes it
 * caches is testbench.yaml's business, and an earlier version of this test
 * asserted `users/me` came back from the cache while the URI it was actually
 * seeing came from the Workbench's own contract, which happens to declare the same
 * path. It went green either way and measured nothing.
 *
 * Split, both halves are true: the command works end to end, and *our* routes are
 * the ones proven serializable.
 */

// Half one: the real command, end to end. What it caches is not ours to decide,
// but that it succeeds and writes PHP the application can load back is.
it('survives a real route:cache run', function (): void {
    $cached = app()->getCachedRoutesPath();
    @unlink($cached);

    try {
        expect(app(Kernel::class)->call('route:cache'))->toBe(0)
            ->and(is_file($cached))->toBeTrue();

        // The cache is PHP built with var_export, which renders an object as
        // `\Foo::__set_state(...)` and is not loadable for most classes. Requiring
        // it back is what proves the file is usable rather than merely written.
        require $cached;

        expect(app('router')->getRoutes()->getRoutes())->not->toBeEmpty();
    } finally {
        @unlink($cached);
    }
});

// Half two, and the one about this package: the generated routes specifically go
// through every step the command performs, on the collection that actually holds
// them. Scoped to our own routes so a green result cannot be about somebody
// else's.
it('puts the generated routes through every step route:cache performs', function (): void {
    $routes = new RouteCollection;

    foreach (generatedRoutes() as $route) {
        $routes->add($route);
    }

    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    $compiled = $routes->compile();

    $file = tempnam(sys_get_temp_dir(), 'lsf-routes-').'.php';
    file_put_contents($file, '<?php return '.var_export($compiled, true).';');

    try {
        expect(require $file)->toEqual($compiled);
    } finally {
        @unlink($file);
    }
});
