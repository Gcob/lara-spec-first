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

// A later template with more segments than the earlier one can match is not
// shadowed by it — the check answers what the compiler answers, not "both of
// these are templated".
it('does not flag a longer templated path the earlier one could never match', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/users/{id}/posts', 'listUserPosts'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([]);
});

// The template-over-template case, and the worse half of shadowing: a
// GitHub-style catch-all matches every two-segment GET, so everything written
// after it at that depth is a whole resource that will never be reached — not
// one endpoint. This used to be reported as clean, because the check skipped
// any later operation whose own path was templated.
it('flags an earlier catch-all template that shadows a later templated path', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/{owner}/{repo}', 'showRepository'),
        doctorOperation(1, HttpMethod::Get, '/users/{id}', 'showUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($result->findings[0]->message)->toContain('GET /{owner}/{repo}')
        ->and($result->findings[0]->message)->toContain('GET /users/{id}')
        ->and($result->findings[0]->message)->toContain('never be reached');
});

// A partial overlap is not shadowing, and saying so would be false: an earlier
// `/users/{id}` swallows `/{a}/{b}` only for requests whose first segment is
// `users`, and the second route answers every other value fine.
it('does not flag a later template the earlier one only partly overlaps', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/{owner}/{repo}', 'showRepository'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings)->toBe([]);
});

// The finding names the operation that loses, because that is the one a reader
// has to move. Built through `DocumentPointer`, so this pointer and the one the
// generated file's own source map carries are the same string.
it('points at the operation that will never be reached', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
        doctorOperation(1, HttpMethod::Get, '/users/me', 'showCurrentUser'),
    ], 'App\\Http\\Generated', '/app/openapi.yaml');

    expect($result->findings[0]->pointer)->toBe('#/paths/~1users~1me/get');
});

// The trailing backslash a `generated.namespace` may be written with is trimmed
// once, for the route target this section prints as much as for the planner.
it('prints a route target with no doubled separator when the namespace is written with a trailing backslash', function (): void {
    $result = RoutingOutcomeCheck::check([
        doctorOperation(0, HttpMethod::Get, '/users/{id}', 'showUser'),
    ], 'App\\Http\\Generated\\', '/app/openapi.yaml');

    expect($result->routes[0]->target)->toBe('App\\Http\\Generated\\Controllers\\ShowUserController')
        ->and($result->routes[0]->target)->not->toContain('\\\\');
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
