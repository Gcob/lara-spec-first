<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\RequestName;

/*
 * Two sources, one suffix, and one case that produces no name at all.
 *
 * The derivation is shared with `ControllerName` rather than copied, so what is
 * pinned here is the part that differs: the suffix, the absence of
 * `x-controller` as a source, and the rule that an operation stating nothing
 * about its input gets no class.
 *
 * @see docs/guide/code-generation/request-validation.md — "Nothing to validate, no class"
 */

/**
 * One operation, with only what a name depends on named.
 */
function namedOperation(
    ?string $operationId = 'createUser',
    string $method = 'post',
    string $path = '/users',
    bool $withBody = true,
    ?string $controller = null,
): Operation {
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: $operationId,
        controller: $controller,
        requestBody: $withBody ? new RequestBody(['application/json' => new Schema], true) : null,
    );
}

it('takes the operationId and adds the suffix', function (): void {
    expect(RequestName::for(namedOperation())?->shortName)->toBe('CreateUserRequest');
});

it('derives the name from the method and path when there is no operationId', function (): void {
    expect(RequestName::for(namedOperation(operationId: null, method: 'patch', path: '/users/{id}'))?->shortName)
        ->toBe('PatchUsersIdRequest');
});

// `x-controller` names a controller and nothing else. Reading it here would
// make one declaration rename two classes.
it('ignores x-controller', function (): void {
    $operation = namedOperation(controller: 'App\\Http\\Controllers\\PeopleController');

    expect(RequestName::for($operation)?->shortName)->toBe('CreateUserRequest');
});

// Scenario: an operation with no request body.
it('gives no name to an operation with nothing to validate', function (): void {
    expect(RequestName::for(namedOperation(withBody: false)))->toBeNull();
});

it('gives a name to an operation whose only input is a query parameter', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users'),
        operationId: 'listUsers',
        queryParameters: [new QueryParameter('page', new Schema)],
    );

    expect(RequestName::for($operation)?->shortName)->toBe('ListUsersRequest');
});

// A body declared only in a media type no rule set reads would make a class
// whose `rules()` returns an empty array. The build reports it instead.
it('gives no name to an operation whose body no rule set reads', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody(['application/xml' => new Schema], true),
    );

    expect(RequestName::for($operation))->toBeNull();
});

// Checked before the suffix, because appending it would produce the valid,
// meaningless `Request` — which every other such operation also becomes.
it('refuses an operationId that leaves nothing behind', function (): void {
    expect(fn () => RequestName::for(namedOperation(operationId: '---')))
        ->toThrow(UnusableNameException::class, 'leaves nothing behind');
});

it('places the class under the Requests sub-namespace', function (): void {
    $name = RequestName::for(namedOperation());

    expect($name?->fullyQualifiedName('App\\Http\\Generated'))
        ->toBe('App\\Http\\Generated\\Requests\\CreateUserRequest')
        ->and($name?->relativePath())->toBe('Requests/CreateUserRequest.php');
});
