<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Scaffolding\OperationLocation;
use Gcob\LaraSpecFirst\Scaffolding\OperationLocator;

/*
 * Finding an operation's line by reading the document as text, because parsing and
 * re-emitting a specification destroys comments, key order and anchors — in the one
 * file a team reads in every pull request.
 *
 * Most of these assert a refusal. Every shape the locator cannot read with
 * certainty has to answer null, so that the command prints the row for a human
 * instead of editing blind.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */

function locate(string $document, string $method = 'get', string $path = '/users/{id}'): ?OperationLocation
{
    return (new OperationLocator)->locate($document, new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: null,
    ));
}

it('finds the operation and the indentation its own keys use', function (): void {
    $location = locate(<<<'YAML'
        openapi: 3.0.3
        paths:
            /users/{id}:
                get:
                    operationId: showUser
        YAML);

    expect($location?->line)->toBe(4)
        ->and($location?->indentation)->toBe('            ');
});

// Two spaces, four, or something stranger: the insertion copies what it found, so
// the document's own style is never normalized by an edit.
it('takes the indentation from the document rather than a step of its own', function (): void {
    $location = locate("openapi: 3.0.3\npaths:\n  /users/{id}:\n    get:\n      operationId: showUser\n");

    expect($location?->indentation)->toBe('      ');
});

// A path key is routinely quoted, because YAML needs it for a value starting with a
// character it reserves. It is the same key either way.
it('reads a quoted path key', function (string $key): void {
    expect(locate("paths:\n  {$key}:\n    get:\n      operationId: showUser\n")?->line)->toBe(3);
})->with(['/users/{id}', "'/users/{id}'", '"/users/{id}"']);

// The document's own comments and blank lines sit at whatever depth their author
// liked, so reading either as structure would end the search inside the block it
// was looking through.
it('looks past comments and blank lines', function (): void {
    $location = locate(<<<'YAML'
        paths:
            # The templated path.
            /users/{id}:

                # The operation.
                get:
                    operationId: showUser
        YAML);

    expect($location?->line)->toBe(6);
});

// The trap a naive search falls into: `get` exists under both paths, and the one
// under `/posts` is not this operation's.
it('does not find a method under a different path', function (): void {
    $location = locate(<<<'YAML'
        paths:
            /posts:
                get:
                    operationId: listPosts
            /users/{id}:
                get:
                    operationId: showUser
        YAML);

    expect($location?->line)->toBe(6);
});

it('finds the right method when a path declares several', function (): void {
    $document = <<<'YAML'
        paths:
            /users/{id}:
                get:
                    operationId: showUser
                delete:
                    operationId: removeUser
        YAML;

    expect(locate($document, 'delete')?->line)->toBe(5);
});

describe('what it refuses to locate', function (): void {
    // The same document, and an entirely different editing problem: there is no
    // line to add one to.
    it('refuses a flow-style operation', function (): void {
        expect(locate("paths:\n  /users/{id}:\n    get: { operationId: showUser }\n"))->toBeNull();
    });

    it('refuses a flow-style path item', function (): void {
        expect(locate("paths:\n  /users/{id}: { get: { operationId: showUser } }\n"))->toBeNull();
    });

    // JSON is a valid specification this package reads. It is not a document with
    // YAML indentation to match, so it is left to a human.
    it('refuses a JSON document', function (): void {
        expect(locate('{"paths": {"/users/{id}": {"get": {"operationId": "showUser"}}}}'))->toBeNull();
    });

    // The operation is really written in another file, so the line under this
    // `$ref` is not where its keys live.
    it('refuses a path item that lives in another file', function (): void {
        expect(locate("paths:\n  /users/{id}:\n    \$ref: './users.yaml'\n"))->toBeNull();
    });

    it('refuses a document with no paths block', function (): void {
        expect(locate("openapi: 3.1.0\ncomponents: {}\n"))->toBeNull();
    });

    it('refuses an operation the document does not declare', function (): void {
        expect(locate("paths:\n  /users/{id}:\n    get:\n      operationId: showUser\n", 'post'))->toBeNull();
    });

    // Nothing to learn the indentation from, and guessing it is what this class
    // exists not to do.
    it('refuses an operation with no keys of its own', function (): void {
        expect(locate("paths:\n  /users/{id}:\n    get:\n  /posts:\n    get:\n      operationId: x\n"))
            ->toBeNull();
    });
});
