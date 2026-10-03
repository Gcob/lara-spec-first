<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\InputDtoPlan;
use Gcob\LaraSpecFirst\Generation\InputDtoPlanner;
use Gcob\LaraSpecFirst\Generation\InputDtoType;
use Gcob\LaraSpecFirst\Generation\PlannedInputDto;
use Gcob\LaraSpecFirst\Generation\PlannedRequest;
use Gcob\LaraSpecFirst\Generation\RequestName;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;

/*
 * What the planner decides about an input DTO: its name, which operation reads
 * it, which properties it has and what each one is, and what it refuses.
 *
 * What the emitted file contains belongs to InputDtoEmitterTest, and what a
 * generated DTO does at run time to GeneratedInputDtoTest.
 *
 * @see docs/guide/code-generation/request-validation.md — "Two directions, two types"
 */

/**
 * One operation carrying a body, in memory.
 *
 * @param  array<string, Schema>  $content
 */
function dtoOperation(
    string $operationId,
    array $content,
    string $method = 'post',
    bool $required = true,
    string $path = '/things',
): Operation {
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: $operationId,
        requestBody: new RequestBody($content, $required),
    );
}

/**
 * The plan for these operations, built the way the build builds it.
 *
 * @param  list<Operation>  $operations
 */
function planDtos(array $operations): InputDtoPlan
{
    $requests = array_map(static function (Operation $operation): PlannedRequest {
        $name = RequestName::for($operation);
        assert($name !== null);

        return new PlannedRequest($operation, $name, RuleSetBuilder::for($operation), 'Controller');
    }, $operations);

    return (new InputDtoPlanner)->plan($requests);
}

/**
 * @param  list<PlannedInputDto>  $dtos
 * @return list<string>
 */
function dtoNames(array $dtos): array
{
    return array_map(static fn (PlannedInputDto $dto): string => $dto->shortName, $dtos);
}

function dtoNamed(InputDtoPlan $plan, string $shortName): PlannedInputDto
{
    foreach ($plan->dtos as $dto) {
        if ($dto->shortName === $shortName) {
            return $dto;
        }
    }

    throw new RuntimeException('No DTO named '.$shortName);
}

/**
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 */
function dtoSchema(array $properties, array $required = [], ?string $name = null, ?string $source = null): Schema
{
    return new Schema(
        name: $name,
        source: $source,
        types: [SchemaType::Object],
        properties: $properties,
        required: $required,
    );
}

function dtoString(): Schema
{
    return new Schema(types: [SchemaType::String]);
}

it('names a DTO after the schema, with Input, and generates a partial beside it', function (): void {
    $plan = planDtos([dtoOperation('createUser', [
        'application/json' => dtoSchema(['a' => dtoString()], name: 'NewUser', source: '#/components/schemas/NewUser'),
    ])]);

    expect(dtoNames($plan->dtos))->toBe(['NewUserInputDto', 'NewUserPartialInputDto'])
        ->and(dtoNamed($plan, 'NewUserInputDto')->position)->toBe('#/components/schemas/NewUser');
});

// The marker is on the request side always, so one `$ref` used both ways does
// not collide with its response DTO.
it('names an inline body after the operation\'s request', function (): void {
    $plan = planDtos([dtoOperation('createUser', ['application/json' => dtoSchema(['a' => dtoString()])])]);

    expect(dtoNames($plan->dtos))->toBe(['CreateUserInputDto', 'CreateUserPartialInputDto'])
        ->and(dtoNamed($plan, 'CreateUserInputDto')->position)
        ->toBe('#/paths/~1things/post/requestBody/content/application~1json/schema');
});

it('names an inline object after its parent and the property', function (): void {
    $plan = planDtos([dtoOperation('createUser', ['application/json' => dtoSchema([
        'address' => dtoSchema(['city' => dtoString()]),
        'roles' => new Schema(types: [SchemaType::Array], items: dtoSchema(['name' => dtoString()])),
    ])])]);

    expect(dtoNames($plan->dtos))->toContain('CreateUserAddressInputDto')
        ->toContain('CreateUserRolesItemInputDto')
        ->and(dtoNamed($plan, 'CreateUserAddressInputDto')->position)
        ->toBe('#/paths/~1things/post/requestBody/content/application~1json/schema/properties/address');
});

// A nested schema's position is inside the component that holds it, so two
// operations reaching one component report one position.
it('reports an inline object\'s position inside the component holding it', function (): void {
    $plan = planDtos([dtoOperation('createUser', ['application/json' => dtoSchema(
        ['address' => dtoSchema(['city' => dtoString()])],
        name: 'NewUser',
        source: 'other.yaml#/components/schemas/NewUser',
    )])]);

    expect(dtoNamed($plan, 'NewUserAddressInputDto')->position)
        ->toBe('other.yaml#/components/schemas/NewUser/properties/address');
});

it('names a nested $ref after its schema, wherever it is written', function (): void {
    $address = dtoSchema(['city' => dtoString()], name: 'Address', source: 'other.yaml#/components/schemas/Address');
    $plan = planDtos([dtoOperation('createUser', ['application/json' => dtoSchema(['address' => $address])])]);

    expect(dtoNames($plan->dtos))->toContain('AddressInputDto')
        ->and(dtoNamed($plan, 'AddressInputDto')->position)->toBe('other.yaml#/components/schemas/Address');
});

// A whole file has no `#` to put a step after, and gains one.
it('reports a position inside a schema that is a whole file', function (): void {
    $plan = planDtos([dtoOperation('createUser', ['application/json' => dtoSchema(
        ['address' => dtoSchema(['city' => dtoString()])],
        name: 'Pet',
        source: 'schemas/Pet.yaml',
    )])]);

    expect(dtoNamed($plan, 'PetAddressInputDto')->position)->toBe('schemas/Pet.yaml#/properties/address');
});

it('shares one DTO between the operations that send one schema', function (): void {
    $schema = dtoSchema(['a' => dtoString()], ['a'], 'NewUser', '#/components/schemas/NewUser');
    $plan = planDtos([
        dtoOperation('createUser', ['application/json' => $schema]),
        dtoOperation('importUser', ['application/json' => clone $schema], path: '/imports'),
    ]);

    expect(dtoNames($plan->dtos))->toBe(['NewUserInputDto', 'NewUserPartialInputDto'])
        ->and(dtoNamed($plan, 'NewUserInputDto')->readers)->toBe(['post /things', 'post /imports'])
        ->and($plan->readBy)->toBe(['post /things' => 'NewUserInputDto', 'post /imports' => 'NewUserInputDto']);
});

it('reads the type each method\'s rule set describes', function (string $method, bool $required, string $expected): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a' => dtoString()], ['a'])], $method, $required)]);

    expect($plan->readBy[$method.' /things'])->toBe($expected);
})->with([
    'a POST' => ['post', true, 'DoThingInputDto'],
    'a PUT' => ['put', true, 'DoThingInputDto'],
    'a PATCH' => ['patch', true, 'DoThingPartialInputDto'],
    'a body that may be absent' => ['post', false, 'DoThingPartialInputDto'],
]);

it('lists no reader for the partial type nobody reads', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a' => dtoString()])])]);

    expect(dtoNamed($plan, 'DoThingPartialInputDto')->readers)->toBe([]);
});

// Both types for every body, even when they come out identical.
it('generates the partial type even for a schema with nothing required', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a' => dtoString()])])]);

    expect(dtoNames($plan->dtos))->toBe(['DoThingInputDto', 'DoThingPartialInputDto']);
});

it('marks a property optional unless the schema requires it, and every one on the partial type', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(
        ['a' => dtoString(), 'b' => dtoString()],
        ['a'],
    )])]);

    $optional = static fn (string $class): array => array_map(
        static fn ($property): bool => $property->optional,
        dtoNamed($plan, $class)->properties,
    );

    expect($optional('DoThingInputDto'))->toBe([false, true])
        ->and($optional('DoThingPartialInputDto'))->toBe([true, true]);
});

// Nullable and optional are two axes.
it('keeps nullable apart from optional', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(
        ['a' => new Schema(types: [SchemaType::String, SchemaType::Null])],
        ['a'],
    )])]);

    $property = dtoNamed($plan, 'DoThingInputDto')->properties[0];

    expect($property->optional)->toBeFalse()
        ->and($property->type->nullable)->toBeTrue();
});

it('has no DTO for a body that is not an object, or for no body', function (): void {
    $plan = planDtos([
        dtoOperation('doThing', ['application/json' => new Schema(types: [SchemaType::Array], items: dtoString())]),
        new Operation(
            index: 1,
            method: HttpMethod::Get,
            path: PathTemplate::fromString('/things'),
            operationId: 'listThings',
            queryParameters: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
        ),
    ]);

    expect($plan->dtos)->toBe([])
        ->and($plan->readBy)->toBe([]);
});

// The decision this exists for: the name is the schema's, so two different
// schemas that give one name are refused, naming where each is written.
it('refuses two different schemas under one name, naming both positions', function (): void {
    $first = dtoSchema(['a' => dtoString()], name: 'Pet', source: '#/components/schemas/Pet');
    $second = dtoSchema(['b' => dtoString()], name: 'Pet', source: 'other.yaml#/components/schemas/Pet');

    expect(fn () => planDtos([
        dtoOperation('createPet', ['application/json' => $first]),
        dtoOperation('importPet', ['application/json' => $second], path: '/imports'),
    ]))->toThrow(UnusableNameException::class, '"#/components/schemas/Pet" and at "other.yaml#/components/schemas/Pet"');
});

// A component and a whole file both called `Pet`.
it('refuses a nested schema that takes the name of another', function (): void {
    $nested = dtoSchema(['b' => dtoString()], name: 'Pet', source: 'Pet.yaml');
    $root = dtoSchema(['pet' => $nested], name: 'Pet', source: '#/components/schemas/Pet');

    expect(fn () => planDtos([dtoOperation('createPet', ['application/json' => $root])]))
        ->toThrow(UnusableNameException::class, 'both generate the input DTO "PetInputDto"');
});

it('refuses a component name that is no class name', function (): void {
    $schema = dtoSchema(['a' => dtoString()], name: '2fa', source: '#/components/schemas/2fa');

    expect(fn () => planDtos([dtoOperation('doThing', ['application/json' => $schema])]))
        ->toThrow(UnusableNameException::class, 'The schema "2fa", written at "#/components/schemas/2fa"');
});

// `user-id` and `user_id` are two keys of the contract and one property.
it('refuses two keys that derive one property name', function (): void {
    expect(fn () => planDtos([dtoOperation('doThing', ['application/json' => dtoSchema([
        'user-id' => dtoString(),
        'user_id' => dtoString(),
    ])])]))->toThrow(UnusableNameException::class, 'The keys "user-id" and "user_id"');
});

it('derives a property name from a key that is not an identifier', function (string $key, string $name): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema([$key => dtoString()])])]);

    $property = dtoNamed($plan, 'DoThingInputDto')->properties[0];

    expect($property->name)->toBe($name)
        ->and($property->key)->toBe($key);
})->with([
    'an identifier' => ['created_at', 'created_at'],
    'a hyphen' => ['user-id', 'user_id'],
    'a leading digit' => ['2fa', '_2fa'],
    'a dot' => ['user.name', 'user_name'],
    'this' => ['this', '_this'],
    'a space' => ['first name', 'first_name'],
]);

// Laravel reads a `*` as a wildcard with no escape, so the rule set emits nothing
// for the key and the validated payload never carries it.
it('has no property for a key the request cannot validate', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a*' => dtoString(), 'b' => dtoString()], ['a*'])])]);
    $dto = dtoNamed($plan, 'DoThingInputDto');

    expect(array_map(static fn ($property): string => $property->key, $dto->properties))->toBe(['b'])
        ->and(implode("\n", $dto->findings))->toContain('`a*` carries a `*`');
});

it('types a file part as a file only in a multipart body', function (array $mediaTypes, string $kind): void {
    $file = new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png');
    $plan = planDtos([dtoOperation('doThing', array_fill_keys($mediaTypes, dtoSchema(['avatar' => $file])))]);

    expect(dtoNamed($plan, 'DoThingInputDto')->properties[0]->type->kind)->toBe($kind);
})->with([
    'multipart' => [['multipart/form-data'], InputDtoType::FILE],
    'JSON' => [['application/json'], InputDtoType::STRING],
    'multipart and JSON' => [['multipart/form-data', 'application/json'], InputDtoType::STRING],
]);

it('points a recursive node at the DTO it recurses into', function (): void {
    $node = new Schema(
        name: 'Node',
        source: '#/components/schemas/Node',
        types: [SchemaType::Object],
        properties: ['next' => Schema::recursion('#/components/schemas/Node', 'Node')],
    );
    $plan = planDtos([dtoOperation('doThing', ['application/json' => $node])]);

    $next = dtoNamed($plan, 'NodeInputDto')->properties[0];

    expect($next->type->kind)->toBe(InputDtoType::DTO)
        ->and($next->type->class)->toBe('NodeInputDto')
        // Nothing generated twice: one full type and one partial.
        ->and(dtoNames($plan->dtos))->toBe(['NodeInputDto', 'NodePartialInputDto']);
});

it('types a recursive node with no name as mixed and says so', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(
        ['next' => Schema::recursion('#/paths/~1x/post')],
    )])]);
    $dto = dtoNamed($plan, 'DoThingInputDto');

    expect($dto->properties[0]->type->kind)->toBe(InputDtoType::MIXED)
        ->and(implode("\n", $dto->findings))->toContain('points back at `#/paths/~1x/post`');
});

// Decision 2 of the card #35 plan: a schema no single rule expresses is read as it
// came, and the finding sends the reader to the request's.
it('types a property with no single type as mixed and says so', function (Schema $property, string $reason): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a' => $property])])]);
    $dto = dtoNamed($plan, 'DoThingInputDto');

    expect($dto->properties[0]->type->kind)->toBe(InputDtoType::MIXED)
        ->and(implode("\n", $dto->findings))->toContain('`a` states '.$reason.', so it is `mixed`');
})->with([
    'a union' => [new Schema(types: [SchemaType::String, SchemaType::Integer]), 'more than one type'],
    'no type' => [new Schema, 'no type'],
]);

// The 3.0 way of writing a `$ref` that carries a sibling keyword.
it('takes the name of the one schema an allOf wraps', function (): void {
    $wrapper = new Schema(allOf: [dtoSchema(['a' => dtoString()], name: 'Pet', source: '#/components/schemas/Pet')]);
    $plan = planDtos([dtoOperation('doThing', ['application/json' => $wrapper])]);

    expect(dtoNames($plan->dtos))->toBe(['PetInputDto', 'PetPartialInputDto']);
});

it('does not name a composition after one of its branches', function (): void {
    $composition = new Schema(allOf: [
        dtoSchema(['a' => dtoString()], name: 'Base', source: '#/components/schemas/Base'),
        dtoSchema(['b' => dtoString()], name: 'Extra', source: '#/components/schemas/Extra'),
    ]);
    $plan = planDtos([dtoOperation('doThing', ['application/json' => $composition])]);

    expect(dtoNames($plan->dtos))->toBe(['DoThingInputDto', 'DoThingPartialInputDto'])
        ->and(array_map(static fn ($property): string => $property->key, dtoNamed($plan, 'DoThingInputDto')->properties))
        ->toBe(['a', 'b']);
});

// An enumeration that is cast is an `int`, and one that is read is a literal
// union: the docblock must be a claim the code beside it can keep.
it('keeps a literal union for a string enumeration only', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema([
        'status' => new Schema(types: [SchemaType::String], enum: ['a', 'b']),
        'level' => new Schema(types: [SchemaType::Integer], enum: [1, 2]),
        'untyped' => new Schema(enum: ['x', 'y']),
        'untypedInts' => new Schema(enum: [1, 2]),
    ])])]);

    $literals = array_map(
        static fn ($property): array => $property->type->literals,
        dtoNamed($plan, 'DoThingInputDto')->properties,
    );

    expect($literals)->toBe([['a', 'b'], [], ['x', 'y'], []])
        ->and(array_map(
            static fn ($property): string => $property->type->native(),
            dtoNamed($plan, 'DoThingInputDto')->properties,
        ))->toBe(['string', 'int', 'string', 'int']);
});

it('treats an enumeration that allows null as nullable', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema([
        'status' => new Schema(types: [SchemaType::String], enum: ['a', null]),
    ])])]);

    $type = dtoNamed($plan, 'DoThingInputDto')->properties[0]->type;

    expect($type->nullable)->toBeTrue()
        ->and($type->literals)->toBe(['a']);
});

it('reads the first media type a rule set reads, in the position it reports', function (): void {
    $plan = planDtos([dtoOperation('doThing', [
        'application/xml' => new Schema,
        'multipart/form-data' => dtoSchema(['a' => dtoString()]),
    ])]);

    expect(dtoNamed($plan, 'DoThingInputDto')->position)
        ->toBe('#/paths/~1things/post/requestBody/content/multipart~1form-data/schema');
});
