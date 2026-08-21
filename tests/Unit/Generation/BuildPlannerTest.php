<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnroutablePathException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/**
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $rows
 * @return list<Operation>
 */
function operations(array $rows): array
{
    $operations = [];

    foreach ($rows as $index => [$method, $path]) {
        $operations[] = new Operation(
            $index,
            HttpMethod::from($method),
            PathTemplate::fromString($path),
            $rows[$index][2] ?? null,
        );
    }

    return $operations;
}

function planner(): BuildPlanner
{
    return new BuildPlanner('App\\Http\\Generated', 'openapi.yaml');
}

/**
 * The routes file out of a plan, by name rather than by position.
 *
 * `end()` would hand back `GeneratedFile|false`, which is a type the tests would
 * then have to talk their way out of. Finding it by the name the runtime looks
 * for also means these tests break if the two ever stop agreeing.
 *
 * @param  list<GeneratedFile>  $files
 */
function routesFile(array $files): GeneratedFile
{
    foreach ($files as $file) {
        if ($file->relativePath === GeneratedRoutesLocator::FILE) {
            return $file;
        }
    }

    throw new RuntimeException('the plan produced no routes file');
}

/**
 * Every URI the plan registers, in the order it registers them.
 *
 * @return list<string>
 */
function registeredUris(GeneratedFile $routes): array
{
    preg_match_all("/Route::\\w+\\((?:\\['\\w+'\\], )?'([^']+)'/", $routes->contents, $matches);

    return $matches[1];
}

it('plans one controller per operation, plus the routes file', function (): void {
    $files = planner()->plan(operations([
        ['get', '/users'],
        ['get', '/users/{id}'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $files))->toBe([
        'Controllers/GetUsersController.php',
        'Controllers/GetUsersIdController.php',
        'routes.php',
    ]);
});

// The document's order settles which of two matching routes answers, so it has
// to survive planning untouched. Sorting anything here would be a heuristic a
// consumer cannot predict from reading their own contract.
it('registers routes in the order the document writes them', function (): void {
    $routes = routesFile(planner()->plan(operations([
        ['get', '/users/me'],
        ['get', '/users/{id}'],
        ['post', '/users'],
    ])));

    expect(registeredUris($routes))->toBe(['/users/me', '/users/{id}', '/users']);
});

it('emits every action as a pair of plain strings', function (): void {
    $routes = routesFile(planner()->plan(operations([['get', '/users']])));

    expect($routes->contents)
        ->toContain("Route::get('/users', [GetUsersController::class, 'routeAction']);");
});

// `Route::head()` does not exist, because Laravel derives HEAD from GET. A
// contract that declares `head` explicitly still has to be routed rather than
// dropped, so it goes through `match()`.
it('routes a verb Laravel has no method for through match', function (): void {
    $routes = routesFile(planner()->plan(operations([['head', '/users']])));

    expect($routes->contents)->toContain("Route::match(['head'], '/users'");
});

// Distinct operations reducing to one class name would mean the second silently
// overwrites the first, which is the kind of guess this package refuses.
it('refuses two operations that claim one class name', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users', 'showUser'],
        ['post', '/accounts', 'show-user'],
    ])))->toThrow(UnusableNameException::class, 'both generate the class name "ShowUserController"');
});

// Symfony's compiler finds placeholders with `[\w\x80-\xFF]+`, so `{user-id}` is
// never recognized as one: it stays literal text and the endpoint answers 404
// forever with nothing reporting it.
it('refuses a path parameter Laravel would never match', function (): void {
    expect(fn () => planner()->plan(operations([['get', '/users/{user-id}']])))
        ->toThrow(UnroutablePathException::class, 'letters, digits and underscores');
});

// The same compiler throws above 32 characters, and it runs while the
// application boots — so the failure would arrive far from its cause.
it('refuses a path parameter longer than the route compiler accepts', function (): void {
    $long = str_repeat('a', UnroutablePathException::PARAMETER_NAME_LIMIT + 1);

    expect(fn () => planner()->plan(operations([['get', '/users/{'.$long.'}']])))
        ->toThrow(UnroutablePathException::class, 'longer than the 32 characters');
});

// The property the whole two-step exists for: a refusal costs nothing, because
// nothing was ever produced for the operations that came before it.
it('produces nothing at all when one operation is refused', function (): void {
    $planned = null;

    try {
        $planned = planner()->plan(operations([
            ['get', '/users'],
            ['get', '/users/{user-id}'],
        ]));
    } catch (UnroutablePathException) {
        // The point is what did not happen.
    }

    expect($planned)->toBeNull();
});

it('carries the marker on every file it plans', function (): void {
    $files = planner()->plan(operations([['get', '/users']]));

    foreach ($files as $file) {
        expect($file->contents)->toContain(GeneratedFile::MARKER);
    }
});
