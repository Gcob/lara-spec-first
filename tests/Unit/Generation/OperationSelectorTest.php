<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\Exceptions\NoSuchOperationException;
use Gcob\LaraSpecFirst\Generation\OperationSelector;

/*
 * What a developer typed, turned into the operations they meant. The singular form
 * is the primitive; `--tag` and `--all` are loops over it rather than a second
 * mechanism.
 *
 * @see docs/guide/code-generation/scaffolding.md — "The build names the command instead of running it"
 */

/**
 * @param  list<array{0: string, 1: string, 2?: string|null, 3?: list<string>}>  $rows
 */
function selector(array $rows): OperationSelector
{
    $operations = [];

    foreach ($rows as $index => $row) {
        $operations[] = new Operation(
            index: $index,
            method: HttpMethod::from($row[0]),
            path: PathTemplate::fromString($row[1]),
            operationId: $row[2] ?? null,
            tags: $row[3] ?? [],
        );
    }

    return new OperationSelector($operations);
}

function selectorFixture(): OperationSelector
{
    return selector([
        ['get', '/users/{id}', 'showUser', ['Users']],
        ['get', '/users', 'listUsers', ['Users']],
        ['post', '/posts', 'createPost', ['Posts']],
        ['delete', '/legacy', null, []],
    ]);
}

it('finds an operation by the operationId its author chose', function (): void {
    $selected = selectorFixture()->named('showUser');

    expect($selected)->toHaveCount(1)
        ->and($selected[0]->identity())->toBe('get /users/{}');
});

// An operation need not have an `operationId`, and one without can still declare
// `x-controller` — so it can still need scaffolding, and still needs a name a
// developer can type.
it('finds an operation by its method and path when it has no operationId', function (): void {
    expect(selectorFixture()->named('delete /legacy')[0]->identity())->toBe('delete /legacy');
});

// `GET /users/{id}` is how everyone writes an endpoint; `get /users/{id}` is how a
// document keys it. Both reach the same operation.
it('reads the method however it was typed', function (string $name): void {
    expect(selectorFixture()->named($name)[0]->identity())->toBe('get /users/{}');
})->with(['get /users/{id}', 'GET /users/{id}', '  GET   /users/{id}  ']);

// Refused rather than treated as an empty selection: a command that created
// nothing and said so reads as "there was nothing to do", when what happened is
// that the name was wrong.
it('refuses a name the contract does not carry', function (): void {
    expect(fn () => selectorFixture()->named('showUsers'))
        ->toThrow(NoSuchOperationException::class, 'No operation in the specification is named');
});

it('selects every operation carrying a tag, in document order', function (): void {
    $selected = selectorFixture()->tagged('Users');

    expect(array_map(fn (Operation $operation): ?string => $operation->operationId, $selected))
        ->toBe(['showUser', 'listUsers']);
});

// Tags are the author's own grouping and this package does not own their spelling:
// matching `users` against `Users` would invent a rule the next contract carrying
// both spellings would be broken by.
it('matches a tag exactly, the way the document writes it', function (): void {
    expect(fn () => selectorFixture()->tagged('users'))
        ->toThrow(NoSuchOperationException::class, 'carries the tag');
});

it('selects everything, and refuses a contract with nothing in it', function (): void {
    expect(selectorFixture()->all())->toHaveCount(4)
        ->and(fn () => selector([])->all())
        ->toThrow(NoSuchOperationException::class, 'describes no operation');
});

// The summary `spec:build` prints, so the counts are ordered rather than however
// the document happened to list them: two tags of equal size would otherwise swap
// places between runs and produce a diff nobody made.
it('counts operations by tag, largest first and then by name', function (): void {
    $counts = selector([
        ['get', '/a', 'a', ['Zebra']],
        ['get', '/b', 'b', ['Apple']],
        ['get', '/c', 'c', ['Users', 'Apple']],
        ['get', '/d', 'd', ['Users']],
        ['get', '/e', 'e', ['Users']],
    ])->countsByTag();

    expect($counts)->toBe(['Users' => 3, 'Apple' => 2, 'Zebra' => 1]);
});

it('finds the operations no tag would reach', function (): void {
    expect(selectorFixture()->untagged())->toHaveCount(1);
});
