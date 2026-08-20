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

// The real command, not a reconstruction of its steps. It works from here for a
// reason worth writing down, because the opposite was assumed first: `route:cache`
// boots a fresh application from the skeleton's bootstrap/app.php, and that file
// reads testbench.yaml, which registers this package. So the collection the
// command caches is the one holding the generated routes.
//
// Requiring the file it wrote is what makes the assertion strong. The cache is
// PHP the application loads at boot, built with var_export, which renders an
// object as `\Foo::__set_state(...)` and is not loadable for most classes. A
// cache that requires cleanly and hands back working routes could not have held
// a closure or an object where a string belongs.
it('survives a real route:cache run', function (): void {
    $cached = app()->getCachedRoutesPath();
    @unlink($cached);

    try {
        expect(app(Kernel::class)->call('route:cache'))->toBe(0);

        require $cached;

        $uris = array_map(
            static fn (Route $route): string => $route->uri(),
            app('router')->getRoutes()->getRoutes(),
        );

        expect($uris)->toContain('users/me')
            ->and($uris)->toContain('users/{id}');
    } finally {
        @unlink($cached);
    }
});
