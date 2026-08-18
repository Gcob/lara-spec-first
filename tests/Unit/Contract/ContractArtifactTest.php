<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\ContractArtifact;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;

/**
 * Operations written in an order that is deliberately not the canonical one, so
 * that a test asserting the order proves something.
 *
 * @return list<Operation>
 */
function documentOrder(): array
{
    return [
        new Operation(0, HttpMethod::Get, PathTemplate::fromString('/users/me'), 'showCurrentUser'),
        new Operation(1, HttpMethod::Delete, PathTemplate::fromString('/users/{id}'), null),
        new Operation(2, HttpMethod::Get, PathTemplate::fromString('/users/{id}'), 'showUser'),
        new Operation(3, HttpMethod::Post, PathTemplate::fromString('/posts'), 'createPost'),
    ];
}

it('keys every operation by its identity', function (): void {
    $artifact = ContractArtifact::fromOperations(documentOrder());

    expect(array_keys($artifact->toArray()['operations']))
        ->toContain('get /users/{}', 'delete /users/{}', 'get /users/me', 'post /posts');
});

it('serializes by endpoint and then by method, not in document order', function (): void {
    $artifact = ContractArtifact::fromOperations(documentOrder());

    expect(array_keys($artifact->toArray()['operations']))->toBe([
        'post /posts',
        'get /users/me',
        'delete /users/{}',
        'get /users/{}',
    ]);
});

it('records document order as data, so reordering the file is visible', function (): void {
    $operations = ContractArtifact::fromOperations(documentOrder())->toArray()['operations'];

    expect($operations['get /users/me']['index'])->toBe(0)
        ->and($operations['post /posts']['index'])->toBe(3);
});

it('records the path as written next to the identity that normalizes it', function (): void {
    $operations = ContractArtifact::fromOperations(documentOrder())->toArray()['operations'];

    expect($operations['get /users/{}']['path'])->toBe('/users/{id}');
});

it('carries its own format version', function (): void {
    expect(ContractArtifact::fromOperations([])->toArray()['artifactVersion'])
        ->toBe(ContractArtifact::FORMAT_VERSION);
});

it('records the lifecycle state of an operation', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/reports'),
        operationId: 'listReports',
        tags: ['reporting'],
        audience: Audience::Internal,
        lifecycle: Lifecycle::Stable,
        deprecated: true,
        sunset: '2026-06-01',
        security: [['bearerAuth' => []]],
    );

    expect(ContractArtifact::fromOperations([$operation])->toArray()['operations']['get /reports'])->toBe([
        'index' => 0,
        'method' => 'get',
        'path' => '/reports',
        'operationId' => 'listReports',
        'tags' => ['reporting'],
        'audience' => 'internal',
        'lifecycle' => 'stable',
        'deprecated' => true,
        'sunset' => '2026-06-01',
        'security' => [['bearerAuth' => []]],
    ]);
});

it('serializes a contract with no operations as an object, not a list', function (): void {
    expect(ContractArtifact::fromOperations([])->toJson())
        ->toContain('"operations": {}');
});

it('leaves the slashes of a path alone in the json', function (): void {
    expect(ContractArtifact::fromOperations(documentOrder())->toJson())
        ->toContain('"path": "/users/me"');
});

it('ends the json with a newline', function (): void {
    expect(ContractArtifact::fromOperations([])->toJson())->toEndWith("}\n");
});
