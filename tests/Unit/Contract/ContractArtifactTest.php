<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\ContractArtifact;
use Gcob\LaraSpecFirst\Contract\Exceptions\DuplicateIdentityException;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;

it('is empty for no operations', function (): void {
    $artifact = ContractArtifact::fromOperations([]);

    expect($artifact->operations())->toBe([])
        ->and($artifact->operation('get /users'))->toBeNull();
});

it('orders operations by path rather than by however they were given', function (): void {
    $artifact = ContractArtifact::fromOperations([
        operationNamed('get', '/zoo'),
        operationNamed('get', '/users'),
    ]);

    expect(array_map(fn (Operation $o): string => $o->path->template, $artifact->operations()))
        ->toBe(['/users', '/zoo']);
});

// The document's own order still matters — it decides which route wins — but
// that is carried on Operation::$index, not on the position operations()
// returns things in.
it('preserves the document index while reordering for display', function (): void {
    $artifact = ContractArtifact::fromOperations([
        operationNamed('get', '/zoo', index: 5),
        operationNamed('get', '/users', index: 1),
    ]);

    expect(array_map(fn (Operation $o): int => $o->index, $artifact->operations()))->toBe([1, 5]);
});

it('orders several methods on one path like a Path Item declares its verbs', function (): void {
    $artifact = ContractArtifact::fromOperations([
        operationNamed('delete', '/users/{id}'),
        operationNamed('get', '/users/{id}'),
        operationNamed('put', '/users/{id}'),
    ]);

    expect(array_map(fn (Operation $o): string => $o->method->value, $artifact->operations()))
        ->toBe(['get', 'put', 'delete']);
});

it('finds an operation by its identity', function (): void {
    $operation = operationNamed('get', '/users/{id}');

    $artifact = ContractArtifact::fromOperations([$operation]);

    expect($artifact->operation('get /users/{}'))->toBe($operation)
        ->and($artifact->operation('post /users/{}'))->toBeNull();
});

it('serializes the format version and every operation in canonical order', function (): void {
    $artifact = ContractArtifact::fromOperations([
        new Operation(1, HttpMethod::Get, PathTemplate::fromString('/users/{id}'), 'showUser'),
        new Operation(0, HttpMethod::Post, PathTemplate::fromString('/users'), null),
    ]);

    expect($artifact->toArray())->toBe([
        'formatVersion' => ContractArtifact::FORMAT_VERSION,
        'operations' => [
            ['index' => 0, 'method' => 'post', 'path' => '/users', 'operationId' => null],
            ['index' => 1, 'method' => 'get', 'path' => '/users/{id}', 'operationId' => 'showUser'],
        ],
    ]);
});

// The serialized path is the template as written, not the normalized form —
// PathTemplate::fromString() needs the parameter names back, and the
// normalized form has already reduced them to `{}`.
it('serializes the path as written, not normalized', function (): void {
    $artifact = ContractArtifact::fromOperations([operationNamed('get', '/users/{id}')]);

    expect($artifact->toArray()['operations'][0]['path'])->toBe('/users/{id}');
});

// A contract keyed by identity cannot hold two operations that address the same
// endpoint, and keeping the last of the two would lose one without a word — the
// failure this package refuses everywhere else. The reading pipeline already
// rejects it upstream; this is for any other caller.
it('refuses two operations that address one endpoint', function (): void {
    expect(fn () => ContractArtifact::fromOperations([
        operationNamed('get', '/users/{id}'),
        operationNamed('get', '/users/{slug}'),
    ]))->toThrow(DuplicateIdentityException::class, 'Two operations address `get /users/{}`');
});
