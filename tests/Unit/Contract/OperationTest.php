<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;

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

/**
 * An operation with every field set to something distinguishable.
 *
 * Nothing is left at its default on purpose: the copy check below reads the
 * constructor with reflection, and a field left at its default would compare
 * equal whether it was copied or dropped, which is the failure the check exists
 * to catch.
 */
function operationWithEveryField(): Operation
{
    $schema = new Schema(types: [SchemaType::String], minLength: 1);

    return new Operation(
        index: 7,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users/{id}'),
        operationId: 'createUser',
        tags: ['Users'],
        audience: Audience::Internal,
        lifecycle: Lifecycle::Stable,
        deprecated: true,
        sunset: '2027-01-01',
        security: [SecurityRequirement::fromSchemes(['bearer' => []])],
        controller: 'App\Http\Controllers\CreateUser',
        requestBody: new RequestBody(['application/json' => $schema], required: true),
        queryParameters: [new QueryParameter('notify', $schema, required: true)],
        responses: [new Response('201', ['application/json' => $schema])],
    );
}

// The scar this check is named after: `Scaffolding\ExtensionInsertion` built
// this copy itself and named every field, and when the class grew three of them
// the copy silently stopped carrying any of them. Reflection rather than a
// hand-written list, so the next field added is covered on the day it is added
// rather than the day somebody remembers.
it('copies every field when it takes a controller', function (): void {
    $original = operationWithEveryField();
    $copy = $original->withController('App\Http\Controllers\Replacement');

    foreach (constructorFieldsOf(Operation::class) as $field) {
        if ($field->getName() === 'controller') {
            continue;
        }

        expect($copy->{$field->getName()})
            ->toEqual($original->{$field->getName()}, "withController() dropped {$field->getName()}");
    }

    expect($copy->controller)->toBe('App\Http\Controllers\Replacement');
});

// And the half that keeps the fixture above honest. Without it, adding a field
// and forgetting to set it here would leave the check passing over a value that
// was never copied, which is the same silence one level up.
it('leaves no field of that fixture at its default', function (): void {
    $operation = operationWithEveryField();

    foreach (constructorFieldsOf(Operation::class) as $field) {
        if (! $field->isDefaultValueAvailable()) {
            continue;
        }

        expect($operation->{$field->getName()})->not->toEqual(
            $field->getDefaultValue(),
            "operationWithEveryField() leaves {$field->getName()} at its default, so the copy check cannot see it"
        );
    }
});

/**
 * @param  class-string  $class
 * @return list<ReflectionParameter>
 */
function constructorFieldsOf(string $class): array
{
    $constructor = (new ReflectionClass($class))->getConstructor();

    // Not an expectation: a class with no constructor would make both checks
    // above pass over nothing, which is the silent-green this file exists
    // against.
    if ($constructor === null) {
        throw new RuntimeException($class.' has no constructor, so there are no fields to check.');
    }

    return $constructor->getParameters();
}
