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
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $rows
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
        );
    }

    return $operations;
}

function planner(): BuildPlanner
{
    return new BuildPlanner('App\\Http\\Generated', 'openapi.yaml');
}

it('plans one controller per operation, plus the routes file', function (): void {
    $files = planner()->plan(operations([
        ['get', '/users'],
        ['get', '/users/{id}'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $files))->toBe([
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
