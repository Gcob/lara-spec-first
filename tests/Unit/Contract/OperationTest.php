<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;

function operationAt(string $method, string $path): Operation
{
    return new Operation(0, HttpMethod::from($method), PathTemplate::fromString($path), null);
}

// Identity exists to be compared, so it reduces every parameter to its position:
// renaming `{id}` to `{userId}` changes nothing a client can observe, and it must
// not read as a different endpoint.
it('identifies an endpoint independently of what its parameters are called', function (): void {
    expect(operationAt('get', '/users/{id}')->identity())
        ->toBe(operationAt('get', '/users/{userId}')->identity())
        ->and(operationAt('get', '/users/{id}')->identity())->toBe('get /users/{}');
});

// A label exists to be read, and `get /users/{}` sends a reader looking for a path
// their document does not contain. Every message this package puts in front of a
// person uses this instead.
it('labels an operation with the path the document actually writes', function (): void {
    expect(operationAt('get', '/users/{id}')->label())->toBe('get /users/{id}');
});

// The pair is only worth having if the two genuinely differ, which is the whole
// reason both exist.
it('keeps the two apart wherever a path is templated', function (): void {
    $operation = operationAt('delete', '/users/{id}/posts/{postId}');

    expect($operation->label())->not->toBe($operation->identity());
});
