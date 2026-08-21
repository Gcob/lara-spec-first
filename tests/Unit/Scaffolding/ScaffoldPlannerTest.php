<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnplaceableClassException;
use Gcob\LaraSpecFirst\Scaffolding\PlannedScaffold;
use Gcob\LaraSpecFirst\Scaffolding\ScaffoldPlanner;

/*
 * What `spec:make` would create, worked out before anything is written — which is
 * what lets the command print that answer and ask first.
 *
 * The paths come from the real autoloader rather than a convention, because a
 * project's own composer.json is the only thing that decides where a class belongs:
 * a scaffold written where the autoloader does not look is a file that compiles and
 * never runs.
 */

/**
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $rows  method, path, x-controller
 * @return list<PlannedScaffold>
 */
function scaffoldPlan(array $rows): array
{
    $operations = [];

    foreach ($rows as $index => $row) {
        $operations[] = new Operation(
            index: $index,
            method: HttpMethod::from($row[0]),
            path: PathTemplate::fromString($row[1]),
            operationId: 'op'.$index,
            controller: $row[2] ?? null,
        );
    }

    return (new ScaffoldPlanner('App\\Http\\Generated'))->plan($operations);
}

it('plans one file per operation that declares a custom controller', function (): void {
    $planned = scaffoldPlan([
        ['get', '/users/{id}', 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController'],
        ['get', '/posts', null],
    ]);

    expect($planned)->toHaveCount(1)
        ->and($planned[0]->class)
        ->toBe('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController');
});

// The path is the autoloader's answer, resolved rather than concatenated: Composer
// records PSR-4 directories relative to `vendor/composer/`, and the raw value
// produces a working but unreadable path this command is about to print.
it('places the file where PSR-4 says the class belongs', function (): void {
    $planned = scaffoldPlan([
        ['get', '/users/{id}', 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\NotWrittenYet'],
    ]);

    expect($planned[0]->path)
        ->toBe(dirname(__DIR__, 2).'/Fixtures/CustomControllers/NotWrittenYet.php')
        ->and($planned[0]->exists)->toBeFalse();
});

// The one condition that makes a scaffold a no-op, and the command reports it
// rather than acting: a class the developer owns is never overwritten.
it('knows when the class has already been written', function (): void {
    $planned = scaffoldPlan([
        ['get', '/users/{id}', 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController'],
    ]);

    expect($planned[0]->exists)->toBeTrue();
});

// The parent takes the child's short name inside the generated namespace, which is
// what lets the scaffold read `extends \…\Generated\Controllers\WrittenController`
// with no alias and no suffix convention to explain.
it('names the generated parent the scaffold will extend', function (): void {
    $planned = scaffoldPlan([
        ['get', '/users/{id}', 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController'],
    ]);

    expect($planned[0]->parent)->toBe('App\\Http\\Generated\\Controllers\\WrittenController');
});

// A refusal rather than a guess. A path invented from the namespace by convention
// would produce a file that compiles, that the autoloader never finds, and whose
// route answers with a class-not-found for a reason nothing in the project states.
it('refuses a class in a namespace nothing maps', function (): void {
    expect(fn () => scaffoldPlan([['get', '/users/{id}', 'Acme\\Nowhere\\UserController']]))
        ->toThrow(UnplaceableClassException::class, 'no PSR-4 prefix in this project maps');
});

// Returned rather than refused, because in bulk this is ordinary: a contract of two
// hundred operations where three declare `x-controller` should scaffold three files
// and say what it did about the rest.
it('separates the operations no scaffold can be planned for', function (): void {
    $operations = [
        new Operation(
            index: 0,
            method: HttpMethod::Get,
            path: PathTemplate::fromString('/users'),
            operationId: 'listUsers',
        ),
    ];

    $planner = new ScaffoldPlanner('App\\Http\\Generated');

    expect($planner->plan($operations))->toBe([])
        ->and($planner->undeclarable($operations))->toHaveCount(1);
});
