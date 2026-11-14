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
use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
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

// The rule set reads a body that names properties and says no type as an
// object, so the DTO must too: a request with rules and no `dto()` would be
// two readings of one schema.
it('has a DTO for a body that names properties and says no type', function (): void {
    $plan = planDtos([dtoOperation('createThing', [
        'application/json' => new Schema(properties: ['a' => dtoString()], required: ['a']),
    ])]);

    expect(dtoNames($plan->dtos))->toBe(['CreateThingInputDto', 'CreateThingPartialInputDto']);
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

// `User` and `UserPartial` are two components and no schema is named wrong: the partial
// type of the first is `UserPartialInputDto`, and so is the full type of the second. The
// refusal says which type of each claims the class, and the suffix that made them meet.
it('says which type of each schema claims the class when a suffix makes two names meet', function (): void {
    $user = dtoSchema(['a' => dtoString()], name: 'User', source: '#/components/schemas/User');
    $userPartial = dtoSchema(['b' => dtoString()], name: 'UserPartial', source: '#/components/schemas/UserPartial');

    $refusal = fn () => planDtos([
        dtoOperation('createUser', ['application/json' => $user]),
        dtoOperation('createUserPartial', ['application/json' => $userPartial], path: '/partials'),
    ]);

    expect($refusal)->toThrow(UnusableNameException::class, 'both generate the input DTO "UserPartialInputDto"')
        ->and($refusal)->toThrow(UnusableNameException::class, 'the schema written at "#/components/schemas/User" gives the partial type, "User" (the schema\'s name) + "PartialInputDto"')
        ->and($refusal)->toThrow(UnusableNameException::class, 'the schema written at "#/components/schemas/UserPartial" gives the full type, "UserPartial" (the schema\'s name) + "InputDto"')
        ->and($refusal)->toThrow(UnusableNameException::class, 'a suffix the generator adds');
});

// An inline `address` of `createUser` and the inline body of `createUserAddress` both
// derive `CreateUserAddress`, one from a parent and a property and one from an operation.
it('says how each inline name was derived when two inline schemas meet', function (): void {
    $refusal = fn () => planDtos([
        dtoOperation('createUser', ['application/json' => dtoSchema(['address' => dtoSchema(['street' => dtoString()])])]),
        dtoOperation('createUserAddress', ['application/json' => dtoSchema(['zip' => dtoString()])], path: '/addresses'),
    ]);

    $nested = '#/paths/~1things/post/requestBody/content/application~1json/schema/properties/address';
    $root = '#/paths/~1addresses/post/requestBody/content/application~1json/schema';

    expect($refusal)->toThrow(UnusableNameException::class, 'both generate the input DTO "CreateUserAddressInputDto"')
        ->and($refusal)->toThrow(UnusableNameException::class, 'the schema written at "'.$nested.'" gives the full type, "CreateUserAddress" (derived from its parent "CreateUser" and the property "address") + "InputDto"')
        ->and($refusal)->toThrow(UnusableNameException::class, 'the schema written at "'.$root.'" gives the full type, "CreateUserAddress" (derived from the request "CreateUserAddressRequest") + "InputDto"');
});

// The two copies are written at two places, so each carries its own position for the `default` nothing
// reads. That is the layout of the document, and the build that took the copies for one schema yesterday
// takes them for one today.
it('shares one DTO between two inline copies of a shape that differ only by where an ignored keyword is written', function (): void {
    $street = static fn (string $at): Schema => new Schema(types: [SchemaType::String], ignored: ['default' => $at.'/default']);

    $plan = planDtos([
        dtoOperation('createUser', ['application/json' => dtoSchema(['address' => dtoSchema(['street' => $street('#/a')])])]),
        dtoOperation('createUserAddress', ['application/json' => dtoSchema(['street' => $street('#/b')])], path: '/addresses'),
    ]);

    // One class, the one the second operation reads and the first nests.
    expect(array_count_values(dtoNames($plan->dtos))['CreateUserAddressInputDto'])->toBe(1)
        ->and($plan->readBy['post /addresses'])->toBe('CreateUserAddressInputDto');
});

// What a rule could be built from still tells two inline copies apart: `[0]` and `[false]` are `==`.
it('still refuses two inline copies whose enums are loosely equal and not the same', function (): void {
    $copy = static fn (mixed $value, string $at): Schema => dtoSchema(['kind' => new Schema(
        types: [SchemaType::Integer],
        enum: [$value],
        ignored: ['default' => $at],
    )]);

    expect(fn () => planDtos([
        dtoOperation('createUser', ['application/json' => dtoSchema(['address' => $copy(0, '#/a')])]),
        dtoOperation('createUserAddress', ['application/json' => $copy(false, '#/b')], path: '/addresses'),
    ]))->toThrow(UnusableNameException::class, 'both generate the input DTO "CreateUserAddressInputDto"');
});

// An object body with no `properties` is an open object whose keys are all undeclared. Its
// type exists, so adding a property later creates no class that was not there, and it has
// nothing to hold: the file says so, and where the body is read from instead.
it('says what an object body with no properties carries, which is nothing', function (): void {
    $plan = planDtos([dtoOperation('receiveHook', ['application/json' => dtoSchema([])])]);

    $dto = dtoNamed($plan, 'ReceiveHookInputDto');

    expect($dto->properties)->toBe([])
        ->and(implode("\n", $dto->findings))
        ->toContain('The body declares no properties, so this type has none and carries nothing')
        ->toContain('`$request->json()->all()`')
        // `post()` is the form bag alone: the uploads of a multipart body are `allFiles()`.
        ->toContain('the form bag and `$request->allFiles()` for a form')
        ->and(implode("\n", $dto->findings))->not->toContain('`$request->post()`');
});

it('says nothing of the kind about a body that declares a property', function (): void {
    $plan = planDtos([dtoOperation('receiveHook', ['application/json' => dtoSchema(['a' => dtoString()])])]);

    expect(implode("\n", dtoNamed($plan, 'ReceiveHookInputDto')->findings))->not->toContain('carries nothing');
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

/**
 * A schema that holds a recursion marker, written as a component.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 */
function recursiveComponent(string $name, array $properties, array $required = [], bool $nullable = false): Schema
{
    return new Schema(
        name: $name,
        source: '#/components/schemas/'.$name,
        types: $nullable ? [SchemaType::Object, SchemaType::Null] : [SchemaType::Object],
        properties: $properties,
        required: $required,
    );
}

// A named recursion is its own class at every level: the request validates down to the depth the
// schema states and refuses what is below, so a DTO built from it is built from checked input.
it('types a named recursion as its own class', function (Schema $node, bool $list, bool $nullable, bool $optional): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => $node])]);
    $dto = dtoNamed($plan, $node->name.'InputDto');
    $property = array_values(array_filter($dto->properties, static fn ($property): bool => $property->key === 'link'))[0];

    expect($property->optional)->toBe($optional)
        ->and($property->type->kind)->toBe($list ? InputDtoType::LIST : InputDtoType::DTO);

    $target = $list ? $property->type->item : $property->type;

    expect($target?->kind)->toBe(InputDtoType::DTO)
        ->and($target?->class)->toBe($node->name.'InputDto')
        ->and($target?->nullable)->toBe($nullable)
        ->and(implode("\n", $dto->findings))->not->toContain('points back')
        // Nothing generated twice: one full type and one partial.
        ->and(dtoNames($plan->dtos))->toBe([$node->name.'InputDto', $node->name.'PartialInputDto']);
})->with([
    'itself' => [
        recursiveComponent('Address', ['street' => dtoString(), 'link' => Schema::recursion('#/components/schemas/Address', 'Address')], ['street']),
        false, false, true,
    ],
    'a list of itself' => [
        recursiveComponent('Category', [
            'name' => dtoString(),
            'link' => new Schema(types: [SchemaType::Array], items: Schema::recursion('#/components/schemas/Category', 'Category')),
        ], ['name']),
        true, false, true,
    ],
    'itself, nullable' => [
        recursiveComponent('Node', ['label' => dtoString(), 'link' => Schema::recursion('#/components/schemas/Node', 'Node')], ['label'], nullable: true),
        false, true, true,
    ],
    'itself, required and not nullable' => [
        recursiveComponent('Chain', ['label' => dtoString(), 'link' => Schema::recursion('#/components/schemas/Chain', 'Chain')], ['label', 'link']),
        false, false, false,
    ],
]);

// A marker the request did not unroll is read as it arrived: no ancestor to read a depth from, or two ancestors where
// the walk follows one. The predicate is the request's own, so the two cannot disagree.
it('types a recursion the request did not unroll as mixed', function (array $operations, string $property): void {
    $plan = planDtos(array_values($operations));
    $dto = array_values(array_filter($plan->dtos, static fn ($dto): bool => ! $dto->partial))[0];
    $found = array_values(array_filter($dto->properties, static fn ($p): bool => $p->key === $property))[0];

    expect($found->type->kind)->toBe(InputDtoType::MIXED)
        ->and(implode("\n", $dto->findings))->toContain('`'.$property.'` points back at');
})->with([
    'an inline ancestor with no name' => [
        [dtoOperation('doThing', ['application/json' => recursiveComponent('Node', ['link' => Schema::recursion('#/components/schemas/Node')])])],
        'link',
    ],
    'a field that is two markers' => [extractFixture('recursion-allof-two-markers.yaml'), 'part'],
]);

// What is written beside a marker holds at every level the request crosses it at. A `required` changes nothing the
// ancestor's class says, but a property does: the class would drop a key the request validated.
it('keeps a recursion mixed when the site writes properties beside the marker', function (): void {
    $link = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        properties: ['extra' => dtoString()],
        recursesTo: '#/components/schemas/Node',
    );
    $plan = planDtos([dtoOperation('doThing', ['application/json' => recursiveComponent('Node', ['label' => dtoString(), 'link' => $link])])]);
    $dto = dtoNamed($plan, 'NodeInputDto');

    expect($dto->properties[1]->type->kind)->toBe(InputDtoType::MIXED)
        ->and(implode("\n", $dto->findings))->toContain('`link` points back at');
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
it('keeps the values of every enumeration, and a literal union for a string one only', function (): void {
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

    expect($literals)->toBe([['a', 'b'], [1, 2], ['x', 'y'], [1, 2]])
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

// One component reached two ways is one schema, and the 3.0 way of writing a `$ref`
// beside an `example` is the second way. Compared by value, the wrapper's `example`
// made them two and the build refused a contract it had always accepted.
it('shares one DTO between a $ref and the same $ref wrapped in an allOf', function (): void {
    $user = dtoSchema(['a' => dtoString()], name: 'User', source: '#/components/schemas/User');
    $wrapped = new Schema(allOf: [$user], examples: [['a' => 'x']]);
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema([
        'owner' => $user,
        'reviewer' => $wrapped,
    ])])]);

    expect(dtoNames($plan->dtos))->toContain('UserInputDto')
        ->and(array_count_values(dtoNames($plan->dtos))['UserInputDto'])->toBe(1);
});

// The same component, reached from two operations: one class, whichever of them is read first.
it('shares one DTO between two operations that reach one recursive pair', function (): void {
    [$fromAuthors, $fromBooks] = authorAndBookWrittenFrom('author');

    $plan = planDtos([
        dtoOperation('createBook', ['application/json' => $fromBooks], path: '/books'),
        dtoOperation('createAuthor', ['application/json' => $fromAuthors], path: '/authors'),
    ]);

    expect(array_count_values(dtoNames($plan->dtos))['AuthorInputDto'])->toBe(1)
        ->and(array_count_values(dtoNames($plan->dtos))['BookInputDto'])->toBe(1);
});

// A name with dots is what Swashbuckle writes with full names, and Java's fully
// qualified names: the characters a class name cannot carry are dropped.
it('derives a class name from a component name with dots', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(
        ['a' => dtoString()],
        name: 'MyApp.Models.NewUser',
        source: '#/components/schemas/MyApp.Models.NewUser',
    )])]);

    expect(dtoNames($plan->dtos))->toBe(['MyAppModelsNewUserInputDto', 'MyAppModelsNewUserPartialInputDto']);
});

// A schema with a file part, sent as multipart by one operation and as another media
// type by the other: a part is a file in one and a string in the other.
it('refuses one schema with a file part sent as multipart and as JSON', function (string $first, string $second): void {
    $file = new Schema(types: [SchemaType::String], isFilePart: true);
    $schema = dtoSchema(['avatar' => $file], name: 'Upload', source: '#/components/schemas/Upload');

    expect(fn () => planDtos([
        dtoOperation('first', [$first => $schema]),
        dtoOperation('second', [$second => $schema], path: '/other'),
    ]))->toThrow(UnusableNameException::class, 'is sent as `multipart/form-data` by one operation and as another media type');
})->with([
    'multipart then JSON' => ['multipart/form-data', 'application/json'],
    'JSON then multipart' => ['application/json', 'multipart/form-data'],
]);

it('refuses it through a nested schema too', function (): void {
    $upload = dtoSchema(['avatar' => new Schema(types: [SchemaType::String], isFilePart: true)], name: 'Upload', source: '#/components/schemas/Upload');
    $outer = dtoSchema(['upload' => $upload]);

    expect(fn () => planDtos([
        dtoOperation('first', ['multipart/form-data' => $outer]),
        dtoOperation('second', ['application/json' => $outer], path: '/other'),
    ]))->toThrow(UnusableNameException::class);
});

// Nothing in the DTO depends on the media type when there is no file part.
it('shares one DTO between a multipart and a JSON operation when there is no file part', function (): void {
    $schema = dtoSchema(['a' => dtoString()], name: 'Plain', source: '#/components/schemas/Plain');
    $plan = planDtos([
        dtoOperation('first', ['multipart/form-data' => $schema]),
        dtoOperation('second', ['application/json' => $schema], path: '/other'),
    ]);

    expect(dtoNames($plan->dtos))->toBe(['PlainInputDto', 'PlainPartialInputDto']);
});

// A nested object's required key Laravel cannot name in `required_array_keys` is
// not enforced by the rules, so a request can reach `from()` without it.
it('makes a nested required key optional when the rules cannot enforce it', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['address' => dtoSchema(
        ['user.name' => dtoString(), 'city' => dtoString()],
        ['user.name', 'city'],
    )], ['address'])])]);

    $optional = static fn ($property): bool => $property->optional;

    expect(array_map($optional, dtoNamed($plan, 'DoThingAddressInputDto')->properties))->toBe([true, false]);
});

// The request emits no presence rule for a required `readOnly` property, so a body can reach
// `from()` without it, at the root and in a nested object alike.
it('makes a required readOnly property optional, at the root and nested', function (): void {
    $id = new Schema(types: [SchemaType::Integer], readOnly: true);
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(
        ['id' => $id, 'name' => dtoString(), 'address' => dtoSchema(['id' => $id, 'city' => dtoString()], ['id', 'city'])],
        ['id', 'name', 'address'],
    )])]);

    $optional = static fn (string $class): array => array_map(
        static fn ($property): bool => $property->optional,
        dtoNamed($plan, $class)->properties,
    );

    expect($optional('DoThingInputDto'))->toBe([true, false, false])
        ->and($optional('DoThingAddressInputDto'))->toBe([true, false])
        ->and(implode("\n", dtoNamed($plan, 'DoThingInputDto')->findings))->toContain('`id` is required by the schema and `readOnly`');
});

/**
 * Whether each property of a DTO is optional, in order.
 *
 * @return list<bool>
 */
function optionalFlags(InputDtoPlan $plan, string $class): array
{
    return array_map(static fn ($property): bool => $property->optional, dtoNamed($plan, $class)->properties);
}

// A form-only body has a rule for the name PHP delivers, so a required one reaches `from()`
// whatever its name, and nothing in the type is hedged.
it('keeps a required property whose name PHP rewrites required when the body is only a form', function (string $mediaType): void {
    $plan = planDtos([dtoOperation('doThing', [$mediaType => dtoSchema(
        ['a b' => dtoString(), 'page[size]' => dtoString(), 'plain' => dtoString()],
        ['a b', 'page[size]', 'plain'],
    )])]);

    expect(optionalFlags($plan, 'DoThingInputDto'))->toBe([false, false, false])
        ->and(implode("\n", dtoNamed($plan, 'DoThingInputDto')->findings))->not->toContain('PHP rewrites');
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A name in a JSON-only body, and a name below the root of a form, are never rewritten: the request
// enforces them, so the type must not claim PHP rewrites them.
it('does not hedge a name PHP does not rewrite there', function (): void {
    $nested = dtoSchema(['a b' => dtoString()], ['a b']);
    $json = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['a b' => dtoString()], ['a b'])])]);
    $form = planDtos([dtoOperation('doThing', ['multipart/form-data' => dtoSchema(['address' => $nested], ['address'])])]);

    expect(optionalFlags($json, 'DoThingInputDto'))->toBe([false])
        ->and(implode("\n", dtoNamed($json, 'DoThingInputDto')->findings))->not->toContain('PHP rewrites')
        ->and(optionalFlags($form, 'DoThingInputDto'))->toBe([false])
        ->and(optionalFlags($form, 'DoThingAddressInputDto'))->toBe([false])
        ->and(implode("\n", dtoNamed($form, 'DoThingAddressInputDto')->findings))->not->toContain('PHP rewrites');
});

// A body that is JSON and a form at once has the JSON name's rule and nothing for the form's, and a
// name two others collide on has none: the request cannot promise either, so the property is optional.
it('makes a required property optional when the request cannot check the name PHP rewrote', function (array $media, array $names): void {
    $schema = dtoSchema(array_fill_keys($names, dtoString()), array_values($names));
    $plan = planDtos([dtoOperation('doThing', array_fill_keys($media, $schema))]);

    expect(optionalFlags($plan, 'DoThingInputDto'))->toBe(array_fill(0, count($names), true))
        ->and(implode("\n", dtoNamed($plan, 'DoThingInputDto')->findings))
        ->toContain('`'.$names[0].'` is required by the schema, but the request cannot check it in a form body');
})->with([
    'JSON and a form' => [['application/json', 'multipart/form-data'], ['a b']],
    'a collision' => [['multipart/form-data'], ['page[size]', 'page']],
]);

// Shared by every operation that reaches the schema, so the shape cannot depend on whether the
// root or a nested use came first.
it('gives a schema one shape whichever operation reaches it first', function (bool $rootFirst): void {
    $shared = dtoSchema(['a b' => dtoString()], ['a b'], 'Shared', '#/components/schemas/Shared');
    $asRoot = dtoOperation('asRoot', ['application/json' => $shared]);
    $nested = dtoOperation('nested', ['application/json' => dtoSchema(['shared' => $shared], ['shared'])], path: '/other');

    $plan = planDtos($rootFirst ? [$asRoot, $nested] : [$nested, $asRoot]);

    expect(dtoNamed($plan, 'SharedInputDto')->properties[0]->optional)->toBeFalse();
})->with(['the root first' => [true], 'the nested use first' => [false]]);

// The same named schema read by a form request that checks its name and by one that cannot: one
// class serves both, so the property is optional whichever request got there first.
it('hedges a shared property when any request reading it cannot check the name', function (bool $formFirst): void {
    $shared = dtoSchema(['a b' => dtoString()], ['a b'], 'Shared', '#/components/schemas/Shared');
    $form = dtoOperation('asForm', ['multipart/form-data' => $shared]);
    $mixed = dtoOperation('asMixed', ['application/json' => $shared, 'multipart/form-data' => $shared], path: '/other');

    $plan = planDtos($formFirst ? [$form, $mixed] : [$mixed, $form]);

    expect(optionalFlags($plan, 'SharedInputDto'))->toBe([true])
        ->and(implode("\n", dtoNamed($plan, 'SharedInputDto')->findings))->toContain('`a b` is required by the schema, but the request cannot check it');
})->with(['the form first' => [true], 'the mixed first' => [false]]);

/**
 * `Author` and `Book` refer to each other, which is the shape of most real contracts.
 *
 * @return array{0: Schema, 1: Schema}
 */
function authorAndBookWrittenFrom(string $entry): array
{
    $bookMarker = Schema::recursion('#/components/schemas/Book', 'Book');
    $authorMarker = Schema::recursion('#/components/schemas/Author', 'Author');

    $book = fn (Schema $author): Schema => dtoSchema(['title' => dtoString(), 'author' => $author], name: 'Book', source: '#/components/schemas/Book');
    $author = fn (Schema $books): Schema => dtoSchema(
        ['name' => dtoString(), 'books' => new Schema(types: [SchemaType::Array], items: $books)],
        name: 'Author',
        source: '#/components/schemas/Author',
    );

    return $entry === 'author'
        ? [$author($book($authorMarker)), $book($author($bookMarker))]
        : [$book($author($bookMarker)), $author($book($authorMarker))];
}

/**
 * The files a build would write for these operations' input DTOs, by class name.
 *
 * @param  list<Operation>  $operations
 * @return array<string, string>
 */
function emittedDtos(array $operations): array
{
    $emitter = new InputDtoEmitter('App\\Http\\Generated', 'openapi.yaml');
    $files = [];

    foreach (planDtos($operations)->dtos as $dto) {
        $files[$dto->shortName] = $emitter->emit($dto)->contents;
    }

    ksort($files);

    return $files;
}

// A schema in a recursive pair is the same class whichever side the walk enters it from: the request unrolls
// both sides to the depth each states, so a DTO is never right for one operation and wrong for another. Compared
// as the files the build would write, byte for byte.
it('types a recursive pair the same in both orders', function (): void {
    [$fromAuthors, $fromBooks] = authorAndBookWrittenFrom('author');
    $authors = dtoOperation('createAuthor', ['application/json' => $fromAuthors], path: '/authors');
    $books = dtoOperation('createBook', ['application/json' => $fromBooks], path: '/books');

    $authorsFirst = emittedDtos([$authors, $books]);

    expect($authorsFirst)->toHaveKeys(['AuthorInputDto', 'BookInputDto'])
        ->and(emittedDtos([$books, $authors]))->toBe($authorsFirst)
        // `books` is a list of `Book`, `author` is an `Author`: each side reads the other as its own class.
        ->and($authorsFirst['AuthorInputDto'])->toContain('public Optional|array $books,')
        ->and($authorsFirst['AuthorInputDto'])->toContain('@param  Optional|list<BookInputDto>  $books')
        ->and($authorsFirst['BookInputDto'])->toContain('public Optional|AuthorInputDto $author,');
});

// A body written `allOf: [Category, {required}]` is a class of its own, so nothing walks `Category` as a schema: the
// marker of `children` is the only place it is reached, and its class has to come from the definition it points at.
it('registers the target class of a recursion behind an allOf', function (): void {
    $plan = planDtos(extractFixture('recursion-allof-class.yaml'));
    $children = static fn (string $class): InputDtoType => array_values(array_filter(
        dtoNamed($plan, $class)->properties,
        static fn ($property): bool => $property->key === 'children',
    ))[0]->type;

    expect(dtoNames($plan->dtos))->toContain('CategoryInputDto')
        ->and($children('CreateThingInputDto')->item?->class)->toBe('CategoryInputDto')
        ->and($children('CategoryInputDto')->item?->class)->toBe('CategoryInputDto')
        // The wrapper's `required` is the wrapper's own: it does not reach the component the marker points at.
        ->and(dtoNamed($plan, 'CategoryInputDto')->properties[0]->optional)->toBeTrue()
        ->and(dtoNamed($plan, 'CreateThingInputDto')->properties[0]->optional)->toBeFalse();
});

// A wrapper that adds `required` is a different schema from the one it points at.
// Named after the branch and shared by position, it gave one class two shapes, and
// the one built first decided: a `$payload['nickname']` read with no guard on a
// request that never had to send it.
it('does not share the class of a $ref with a wrapper that adds a required key', function (bool $wrapperFirst): void {
    $user = dtoSchema(['name' => dtoString(), 'nickname' => dtoString()], ['name'], 'User', '#/components/schemas/User');
    $wrapper = new Schema(allOf: [$user], required: ['nickname']);
    $plain = dtoOperation('plain', ['application/json' => $user]);
    $strict = dtoOperation('strict', ['application/json' => $wrapper], path: '/strict');

    $plan = planDtos($wrapperFirst ? [$strict, $plain] : [$plain, $strict]);

    $optional = static fn (string $class): array => array_map(
        static fn ($property): bool => $property->optional,
        dtoNamed($plan, $class)->properties,
    );

    expect($optional('UserInputDto'))->toBe([false, true])
        ->and(dtoNames($plan->dtos))->toContain('StrictInputDto');
})->with(['the wrapper first' => [true], 'the plain $ref first' => [false]]);

// Optional by necessity, and the file says why: whoever reads the DTO would otherwise
// wonder why a required key is not required.
it('says why a required key with a dot is optional', function (): void {
    $plan = planDtos([dtoOperation('doThing', ['application/json' => dtoSchema(['user.name' => dtoString()], ['user.name'])])]);

    expect(implode("\n", dtoNamed($plan, 'DoThingInputDto')->findings))
        ->toContain('`user.name` is required by the schema, but its name has a `.` or a `,`');
});
