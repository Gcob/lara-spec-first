<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Exceptions\InvalidPathTemplateException;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\BuildPlan;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnroutablePathException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/*
 * Planning, and only planning: which files a document produces, which names they
 * take, what is refused, and that a refusal produces nothing at all.
 *
 * What each emitted file *contains* belongs to the class that decides it, and is
 * asserted there — tests/Unit/Generation/RoutesEmitterTest.php for the
 * registrations and the routes metadata, ControllerEmitterTest.php for the
 * controller's. Asserting it here too would mean one change breaking two files
 * for one reason.
 */

/**
 * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null}>  $rows
 * @return list<Operation>
 */
function operations(array $rows): array
{
    $operations = [];

    foreach ($rows as $index => [$method, $path]) {
        $operations[] = new Operation(
            index: $index,
            method: HttpMethod::from($method),
            path: PathTemplate::fromString($path),
            operationId: $rows[$index][2] ?? null,
            controller: $rows[$index][3] ?? null,
        );
    }

    return $operations;
}

/**
 * A class this repository really autoloads, standing in for a custom controller a
 * project has written.
 *
 * Real rather than plausible, because the question the plan asks about an
 * `x-controller` value is whether the autoloader can find a file for it — and a
 * fake would only prove that a fake answers.
 */
function writtenController(): string
{
    return 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController';
}

function planner(): BuildPlanner
{
    return new BuildPlanner('App\\Http\\Generated', 'openapi.yaml');
}

/**
 * The contents of the routes file a plan produced.
 */
function routesOf(BuildPlan $plan): string
{
    foreach ($plan->files as $file) {
        if ($file->relativePath === GeneratedRoutesLocator::FILE) {
            return $file->contents;
        }
    }

    throw new RuntimeException('the plan produced no routes file');
}

it('plans one controller per operation, plus the routes file', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users'],
        ['get', '/users/{id}'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $plan->files))->toBe([
        'Controllers/GetUsersController.php',
        'Controllers/GetUsersIdController.php',
        GeneratedRoutesLocator::FILE,
    ]);
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

/*
 * Routable and still unusable, which is what makes these a second check rather
 * than a stricter first one. The generated `routeAction` declares one parameter
 * per path parameter — that is what lets a custom controller override it — so a
 * name legal in a route and illegal as a PHP variable would produce a file that
 * does not parse.
 *
 * @see docs/guide/controllers.md — "The signature is the contract with the child"
 */
it('refuses a path parameter that could not be a PHP variable', function (string $path): void {
    expect(fn () => planner()->plan(operations([['get', $path]])))
        ->toThrow(UnusableNameException::class, 'cannot be a PHP variable');
})->with([
    // `\w` accepts a leading digit and Symfony's compiler matches `{2fa}`
    // happily. `$2fa` is not a variable.
    'a leading digit' => '/users/{2fa}',
    // Legal in a route, fatal as a parameter name.
    'this' => '/users/{this}',
]);

// The neighbouring cases, each refused by the class that owns the reason: a
// character the router would never match is `UnroutablePathException`'s, and one
// path naming a parameter twice is refused where the path is read, before anything
// asks what could be generated from it.
it('leaves the neighbouring refusals to the classes that own them', function (
    string $path,
    string $exception,
    string $message,
): void {
    expect(fn () => planner()->plan(operations([['get', $path]])))->toThrow($exception, $message);
})->with([
    'a character the router drops' => ['/users/{user-id}', UnroutablePathException::class, 'letters, digits'],
    'one name twice' => ['/users/{id}/posts/{id}', InvalidPathTemplateException::class, 'declares "id" twice'],
]);

it('accepts a parameter name PHP can carry, whatever it looks like', function (string $path): void {
    expect(planner()->plan(operations([['get', $path]]))->files)->toHaveCount(2);
})->with([
    'ordinary' => '/users/{id}',
    'an underscore first' => '/users/{_private}',
    'digits after a letter' => '/users/{v2Id}',
]);

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

/*
 * The custom-controller half of planning: which class a route ends up pointing at,
 * and the two collisions that answer differently from the `operationId` one.
 *
 * @see docs/guide/controllers.md — "Two classes, found by name rather than by a scan"
 */

it('points the route at the custom controller once that class exists', function (): void {
    $routes = routesOf(planner()->plan(operations([['get', '/users/{id}', 'showUser', writtenController()]])));

    expect($routes)
        ->toContain('use '.writtenController().';')
        ->toContain("Route::get('/users/{id}', [WrittenController::class, 'routeAction']);")
        ->and($routes)->not->toContain('App\\Http\\Generated\\Controllers\\WrittenController');
});

// Until the class exists the generated parent answers, which is what keeps the
// operation at 501 rather than at a class-not-found.
it('points the route at the generated parent while the custom controller is missing', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Controllers\\NotWrittenYetController'],
    ]));

    expect(routesOf($plan))
        ->toContain('use App\\Http\\Generated\\Controllers\\NotWrittenYetController;')
        ->toContain("[NotWrittenYetController::class, 'routeAction']");
});

it('names the generated parent after the class the contract declared', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUserAccountDetails', 'App\\Http\\Controllers\\UserController'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $plan->files))->toBe([
        'Controllers/UserController.php',
        GeneratedRoutesLocator::FILE,
    ]);
});

// Distinct fully-qualified names can still share a short name, and one generated
// parent cannot serve two operations. The message names both, and says the thing
// that actually fixes it rather than mentioning `operationId`, which neither of
// these operations is being named from.
it('refuses two declared controllers that reduce to one generated parent', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Controllers\\Admin\\UserController'],
        ['get', '/accounts/{id}', 'showAccount', 'App\\Http\\Controllers\\Api\\UserController'],
    ])))->toThrow(UnusableNameException::class, 'both generate the same parent');
});

// A child declared inside the generated tree would extend itself, and a build
// rewrites everything under that namespace — so the work would not survive one.
it('refuses a custom controller inside the generated tree', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Generated\\Controllers\\UserController'],
    ])))->toThrow(UnusableNameException::class, 'inside the generated namespace');
});

// A namespace that merely starts with the same characters is a different
// namespace, and refusing it would refuse a legitimate name.
it('accepts a namespace that only looks like the generated one', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\GeneratedThings\\UserController'],
    ]));

    expect($plan->files)->toHaveCount(2);
});

/*
 * What the plan can tell the command. Counted here rather than read off the
 * command's output, because the number is the plan's answer and the sentence
 * around it is the command's.
 */

it('counts the operations whose route lands on their generated parent', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', writtenController()],
        ['get', '/posts', 'listPosts', 'App\\Http\\Controllers\\NotWrittenYetController'],
        ['get', '/health', 'health'],
    ]));

    expect($plan->routedToGeneratedParent())->toBe(2)
        ->and($plan->controllers)->toHaveCount(3);
});
