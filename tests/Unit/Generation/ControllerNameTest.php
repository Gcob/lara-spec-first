<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

function operation(string $method, string $path, ?string $operationId = null): Operation
{
    return new Operation(
        0,
        HttpMethod::from($method),
        PathTemplate::fromString($path),
        $operationId,
    );
}

it('takes the name a developer chose when the operation declares one', function (): void {
    $name = ControllerName::for(operation('get', '/users/{id}', 'showUser'));

    expect($name->shortName)->toBe('ShowUserController')
        ->and($name->wasDeclared)->toBeTrue();
});

// Studly-casing is what makes the ecosystem's usual spellings land on one class
// name, so a contract written in kebab or snake case needs no special handling.
it('reads an operationId in whichever case its author wrote', function (string $operationId): void {
    expect(ControllerName::for(operation('get', '/users', $operationId))->shortName)
        ->toBe('ListActiveUsersController');
})->with(['listActiveUsers', 'list-active-users', 'list_active_users', 'ListActiveUsers']);

// The method first, then the path, which is the order a reader scans an endpoint
// in. Parameter names are part of it: a class with no declared custom controller
// is `final`, so nothing may extend it and no import can depend on the name —
// which is what makes it safe to spend the readability here.
it('derives a name from the method and path when no operationId exists', function (
    string $method,
    string $path,
    string $expected,
): void {
    $name = ControllerName::for(operation($method, $path));

    expect($name->shortName)->toBe($expected)
        ->and($name->wasDeclared)->toBeFalse();
})->with([
    ['get', '/users', 'GetUsersController'],
    ['post', '/users', 'PostUsersController'],
    ['get', '/users/{id}', 'GetUsersIdController'],
    ['get', '/users/{id}/posts', 'GetUsersIdPostsController'],
    ['delete', '/api/v1/order-items/{orderItemId}', 'DeleteApiV1OrderItemsOrderItemIdController'],
    ['get', '/', 'GetController'],
]);

// A refusal rather than a file that will not parse. Emitting invalid PHP would
// turn a contract problem into a syntax error three steps from its cause.
it('refuses an operationId PHP cannot carry', function (string $operationId): void {
    expect(fn () => ControllerName::for(operation('get', '/users', $operationId)))
        ->toThrow(UnusableNameException::class, 'not a usable PHP identifier');
})->with([
    // Studly-casing removes separators and nothing else, so anything it keeps
    // has to be a legal identifier on its own.
    'starts with a digit' => '2fa',
    'punctuation studly keeps' => '!!',
]);

// The subtler half, and the one a regex on the finished name misses: these are
// valid identifiers once the suffix is appended. `---` studlies to nothing, so
// the class would be called `Controller` — a name nobody chose, claimed by every
// other operation whose id also reduces to nothing.
it('refuses an operationId that survives as nothing', function (string $operationId): void {
    expect(fn () => ControllerName::for(operation('get', '/users', $operationId)))
        ->toThrow(UnusableNameException::class, 'leaves nothing behind');
})->with([
    'separators only' => '---',
    'spaces only' => '   ',
    'empty' => '',
]);
