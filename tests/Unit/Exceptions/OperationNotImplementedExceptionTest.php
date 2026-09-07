<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\OperationNotImplementedException;
use Gcob\LaraSpecFirst\Exceptions\SpecException;

/*
 * What an operation nothing implements answers with.
 *
 * The message is asserted here rather than through a request, because how a message
 * reaches a response body is Laravel's error rendering: it differs between the
 * versions this package supports and with `APP_DEBUG`, and neither is what this
 * package promises. What it promises is the status and the words.
 *
 * @see docs/guide/code-generation/scaffolding.md — "An unimplemented operation answers 501"
 */

it('answers 501, which is the status HTTP already has for this', function (): void {
    expect(OperationNotImplementedException::operation('get /users/{id}', 'showUser')->getStatusCode())
        ->toBe(501);
});

it('names the operation a reader would go looking for', function (): void {
    expect(OperationNotImplementedException::operation('get /users/{id}', 'showUser')->getMessage())
        ->toContain('get /users/{id}');
});

// A developer who meets the 501 before they meet the documentation should still
// learn what creates the class that answers it.
it('names the spec:make invocation that implements the operation', function (): void {
    expect(OperationNotImplementedException::operation('get /users/{id}', 'showUser')->getMessage())
        ->toContain('php artisan spec:make showUser');
});

// An operation with no `operationId` has no name to pass, so the command falls back
// to the method and path — quoted, because a path carries characters a shell reads.
it('falls back to the method and path when the operation has no name', function (): void {
    expect(OperationNotImplementedException::operation('get /users/{id}')->getMessage())
        ->toContain('php artisan spec:make "get /users/{id}"');
});

// The marker every refusal in this package carries, so one `catch` is enough for a
// consuming application.
it('is catchable as the package\'s own exception type', function (): void {
    expect(OperationNotImplementedException::operation('get /users'))->toBeInstanceOf(SpecException::class);
});
