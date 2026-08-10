<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Exceptions\InvalidPathTemplateException;
use Gcob\LaraSpecFirst\Contract\PathTemplate;

it('keeps the path exactly as written', function (): void {
    expect(PathTemplate::fromString('/users/{id}')->template)->toBe('/users/{id}');
});

it('reads the parameter names in order', function (string $template, array $expected): void {
    expect(PathTemplate::fromString($template)->parameterNames)->toBe($expected);
})->with([
    ['/users', []],
    ['/users/{id}', ['id']],
    ['/users/{userId}/posts/{postId}', ['userId', 'postId']],
    // Legal OpenAPI, and a shape that will need care further down the pipeline.
    ['/files/{name}.{ext}', ['name', 'ext']],
]);

// The decision this class exists to carry: a parameter's name is documentation,
// so renaming one changes nothing a client can observe and must not read as a
// different endpoint.
it('reduces every parameter to its position', function (string $template, string $expected): void {
    expect(PathTemplate::fromString($template)->normalized)->toBe($expected);
})->with([
    ['/users', '/users'],
    ['/users/{id}', '/users/{}'],
    ['/users/{userId}/posts/{postId}', '/users/{}/posts/{}'],
    ['/files/{name}.{ext}', '/files/{}.{}'],
]);

it('sees a renamed parameter as the same endpoint', function (): void {
    expect(PathTemplate::fromString('/users/{id}')->isSameEndpointAs(PathTemplate::fromString('/users/{userId}')))
        ->toBeTrue();
});

// Two paths that differ only in a parameter name already collide in any router,
// so treating them as one endpoint surfaces a fault rather than creating one.
it('sees two differently-named parameters at one position as colliding', function (): void {
    expect(PathTemplate::fromString('/users/{id}')->isSameEndpointAs(PathTemplate::fromString('/users/{slug}')))
        ->toBeTrue();
});

it('tells different endpoints apart', function (string $a, string $b): void {
    expect(PathTemplate::fromString($a)->isSameEndpointAs(PathTemplate::fromString($b)))->toBeFalse();
})->with([
    ['/users/{id}', '/users/me'],
    ['/users/{id}', '/users/{id}/posts'],
    ['/users', '/accounts'],
]);

it('rejects a path that is not absolute', function (): void {
    expect(fn () => PathTemplate::fromString('users/{id}'))
        ->toThrow(InvalidPathTemplateException::class, 'must begin with "/"');
});

it('rejects an unbalanced template', function (string $template): void {
    expect(fn () => PathTemplate::fromString($template))
        ->toThrow(InvalidPathTemplateException::class, 'unbalanced');
})->with(['/users/{id', '/users/id}', '/users/{a{b}', '/users/{a}}']);

it('rejects a parameter with no name', function (): void {
    expect(fn () => PathTemplate::fromString('/users/{}'))
        ->toThrow(InvalidPathTemplateException::class, 'no name');
});

// Two parameters of one name cannot both be bound, whatever the router.
it('rejects a repeated parameter name', function (): void {
    expect(fn () => PathTemplate::fromString('/users/{id}/posts/{id}'))
        ->toThrow(InvalidPathTemplateException::class, 'declares "id" twice');
});

// Contract\ describes the contract, not what one framework can route. Names that
// Laravel cannot bind are still valid OpenAPI and are carried as written; what
// to do about them belongs to whatever registers routes.
it('carries parameter names a router might refuse', function (string $name): void {
    expect(PathTemplate::fromString('/users/{'.$name.'}')->parameterNames)->toBe([$name]);
})->with(['user-id', 'user.id', 'a_very_long_and_extremely_descriptive_parameter_name']);
