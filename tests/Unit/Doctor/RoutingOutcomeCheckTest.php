<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Doctor\Checks\RoutingOutcomeCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;

// Named distinctly from ControllerNameTest's own `operation()` helper: Pest's
// test files are not namespaced, so two files declaring the same global
// function name would collide the moment both load in one process.
function doctorOperation(int $index, HttpMethod $method, string $path, ?string $operationId = null): Operation
{
    return new Operation($index, $method, PathTemplate::fromString($path), $operationId);
}

it('plans the routes exactly as spec:build would, in document order', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Post, '/posts', 'createPost'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([])
        ->and($result->routes)->toHaveCount(2)
        ->and($result->routes[0]->method)->toBe('GET')
        ->and($result->routes[0]->path)->toBe('/users/{id}')
        ->and($result->routes[0]->target)->toContain('ShowUserController')
        ->and($result->routes[0]->routesToCustomController)->toBeFalse();
});

// The one piece of logic in this PR that is entirely new: an earlier
// templated path matching every request a later literal one was meant to
// answer.
it('flags an earlier templated path that shadows a later literal one', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/users/me', 'showCurrentUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($result->findings[0]->section)->toBe('Routing outcome')
        ->and($result->findings[0]->message)->toContain('GET /users/{id}')
        ->and($result->findings[0]->message)->toContain('GET /users/me')
        ->and($result->findings[0]->message)->toContain('never be reached');
});

// Document order settles which route wins — a literal registered before the
// template it would otherwise collide with is not shadowed by anything.
it('does not flag a literal path registered before the template that would have matched it', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/me', 'showCurrentUser'),
        doctorOperation(1, HttpMethod::Get, '/users/{id}', 'showUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([]);
});

it('does not flag two templated paths against each other', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/users/{id}/posts', 'listUserPosts'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([]);
});

it('does not flag a template and a literal that share no method', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Post, '/users/me', 'updateCurrentUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([]);
});

// The plan itself can refuse — two operations naming one derived class — and
// that becomes a finding rather than an uncaught exception. Classified as a
// package limit: the collision comes from this package's own naming scheme
// running into two distinct, individually valid operationIds, not from the
// document being internally inconsistent.
it('turns a plan the builder refuses into a package limit finding, with no routes', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/accounts/{id}', 'showUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->plan)->toBeNull()
        ->and($result->routes)->toBe([])
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($result->findings[0]->section)->toBe('Routing outcome');
});
