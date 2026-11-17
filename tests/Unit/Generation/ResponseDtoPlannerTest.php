<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\InputDtoProperty;
use Gcob\LaraSpecFirst\Generation\InputDtoType;
use Gcob\LaraSpecFirst\Generation\PlannedResponseDto;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;
use Gcob\LaraSpecFirst\Generation\StatusResponse;

/**
 * An operation answering with a named object schema, under `200` unless it says otherwise.
 */
function answeringWith(string $operationId, string $schemaName, string $source, string $status = '200'): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/'.$operationId),
        operationId: $operationId,
        responses: [new Response($status, ['application/json' => new Schema(
            name: $schemaName,
            source: $source,
            types: [SchemaType::Object],
            properties: ['id' => new Schema(types: [SchemaType::Integer])],
        )])],
    );
}

// A response DTO is named after its schema alone, so two schemas can derive one class name.
it('refuses two schemas that give one response DTO name', function (): void {
    $plan = static fn (): mixed => (new ResponseDtoPlanner)->plan([
        answeringWith('showUser', 'User', '#/components/schemas/User'),
        answeringWith('showMember', 'user', './other.yaml#/components/schemas/user'),
    ]);

    expect($plan)->toThrow(
        UnusableNameException::class,
        'The schemas written at "#/components/schemas/User" and at "./other.yaml#/components/schemas/user" both generate the response DTO "UserDto"',
    );
});

// An inline property's class is derived from its parent, which nobody wrote: the message says what it was derived from
// and that a component name ends the clash, as the request side's does.
it('explains the derived name when an inline property meets a component of the same name', function (): void {
    $user = responseComponent('User', [
        'address' => new Schema(types: [SchemaType::Object], properties: ['street' => responseString()]),
    ]);
    $address = responseComponent('UserAddress', ['street' => responseString()]);

    $plan = static fn (): mixed => (new ResponseDtoPlanner)->plan([
        answeringWithSchema($user, 'showUser'),
        answeringWithSchema($address, 'showAddress'),
    ]);

    expect($plan)->toThrow(UnusableNameException::class, 'the schema written at "#/components/schemas/UserAddress" gives the base "UserAddress" (the schema\'s name)')
        ->and($plan)->toThrow(UnusableNameException::class, '(derived from its parent "User" and the property "address")')
        ->and($plan)->toThrow(UnusableNameException::class, 'give an inline schema a component name of its own');
});

// A response is not a rule set, so its `allOf` refusal names the response and not `rules()`.
it('words an allOf conflict in a response for a response', function (): void {
    $branch = static fn (SchemaType $type): Schema => new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [$type])]);
    $plan = static fn (): mixed => (new ResponseDtoPlanner)->plan([answeringWithSchema(
        new Schema(name: 'Thing', source: '#/components/schemas/Thing', allOf: [$branch(SchemaType::Integer), $branch(SchemaType::String)]),
    )]);

    expect($plan)->toThrow(ConflictingInputException::class, 'under the `200` response.id whose branches disagree')
        ->and($plan)->toThrow(ConflictingInputException::class, 'A response cannot match both');

    try {
        $plan();
    } catch (ConflictingInputException $conflict) {
        expect($conflict->getMessage())->not->toContain('rule set');
    }
});

// Two operations that return one schema are one type, whatever status each answers with.
it('shares one response DTO between operations returning one schema', function (): void {
    $dtos = (new ResponseDtoPlanner)->plan([
        answeringWith('showUser', 'User', '#/components/schemas/User'),
        answeringWith('showMember', 'User', '#/components/schemas/User'),
        answeringWith('createUser', 'User', '#/components/schemas/User', '201'),
    ])->dtos;

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->shortName)->toBe('UserDto')
        ->and($dtos[0]->readers)->toBe([
            ['label' => 'get /showUser', 'status' => '200'],
            ['label' => 'get /showMember', 'status' => '200'],
            ['label' => 'get /createUser', 'status' => '201'],
        ]);
});

/**
 * The response DTOs of one operation of the statuses contract, by class name.
 *
 * @return array<string, PlannedResponseDto>
 */
function statusDtos(string $operationId): array
{
    $operations = array_values(array_filter(
        extractFixture('response-statuses.yaml'),
        static fn (Operation $operation): bool => $operation->operationId === $operationId,
    ));

    return array_column((new ResponseDtoPlanner)->plan($operations)->dtos, null, 'shortName');
}

/**
 * What each status of one operation of the statuses contract answers with.
 *
 * @return list<StatusResponse>
 */
function statusesOf(string $operationId): array
{
    $operation = array_values(array_filter(
        extractFixture('response-statuses.yaml'),
        static fn (Operation $operation): bool => $operation->operationId === $operationId,
    ))[0];

    return (new ResponseDtoPlanner)->plan([$operation])->statuses[$operation->label()];
}

// An inline schema has no name of its own, so the operation and the status give it one, and what is nested in it adds
// its property.
it('names an inline response DTO after its operation and status', function (): void {
    expect(array_keys(statusDtos('createUser')))->toBe([
        'CreateUser201ResponseDto',
        'CreateUser201ResponseAddressDto',
        'CreateUser201ResponseTagsItemDto',
        'CreateUser201ResponseLabelsValueDto',
        'CreateUser2XXResponseDto',
        'CreateUserDefaultResponseDto',
    ]);
});

it('names an inline response DTO after the method and path when the operation has no id', function (): void {
    $operation = array_values(array_filter(
        extractFixture('response-statuses.yaml'),
        static fn (Operation $operation): bool => $operation->operationId === null,
    ))[0];

    expect(array_column((new ResponseDtoPlanner)->plan([$operation])->dtos, 'shortName'))
        ->toBe(['GetHealth200ResponseDto']);
});

it('says how an inline response DTO was named', function (): void {
    $dto = statusDtos('createUser')['CreateUser2XXResponseDto'];

    expect($dto->naming)->toContain('`CreateUser`')->toContain('`2XX`')->toContain('written inline')
        ->and($dto->position)->toBe('#/paths/~1users/post/responses/2XX/content/application~1json/schema');
});

// The card's third scenario: a success and a failure are two types, and an answer with no body is none.
it('gives each documented status its own response type', function (): void {
    $statuses = statusesOf('updateUser');

    expect(array_keys(statusDtos('updateUser')))->toBe(['UserDto', 'ValidationErrorDto'])
        ->and(array_map(static fn (StatusResponse $status): ?string => $status->dto, $statuses))
        ->toBe(['UserDto', 'ValidationErrorDto', null])
        ->and(array_map(static fn (StatusResponse $status): string => $status->status, $statuses))
        ->toBe(['200', '422', '204'])
        ->and($statuses[2]->reason)->toBe('it declares no body');
});

// A list has no class of its own: the array is the body and each item is the DTO.
it('types an array response through its item DTO', function (): void {
    expect(array_keys(statusDtos('listUsers')))->toBe(['UserDto'])
        ->and(statusesOf('listUsers')[0]->depth)->toBe(1)
        ->and(statusesOf('listUsers')[0]->dto)->toBe('UserDto');

    // Inline items take the response's name plus `Item`, and a `+json` media type is JSON.
    expect(array_keys(statusDtos('listEvents')))->toBe(['ListEvents200ResponseItemDto']);

    // A scalar has no DTO, and the status says why.
    expect(statusDtos('countUsers'))->toBe([])
        ->and(statusesOf('countUsers')[0]->dto)->toBeNull()
        ->and(statusesOf('countUsers')[0]->reason)->toBe('its body is not an object or a list');

    expect(statusDtos('exportUsers'))->toBe([])
        ->and(statusesOf('exportUsers')[0]->reason)->toBe('it declares no JSON media type');
});

/**
 * The plan of one operation of a fixture contract.
 *
 * @return array<string, PlannedResponseDto>
 */
function dtosOfFixture(string $fixture, string $operationId): array
{
    $operation = array_values(array_filter(
        extractFixture($fixture),
        static fn (Operation $operation): bool => $operation->operationId === $operationId,
    ))[0];

    return array_column((new ResponseDtoPlanner)->plan([$operation])->dtos, null, 'shortName');
}

/**
 * What each status of one operation of a fixture contract answers with.
 *
 * @return list<StatusResponse>
 */
function statusesOfFixture(string $fixture, string $operationId): array
{
    $operation = array_values(array_filter(
        extractFixture($fixture),
        static fn (Operation $operation): bool => $operation->operationId === $operationId,
    ))[0];

    return (new ResponseDtoPlanner)->plan([$operation])->statuses[$operation->label()];
}

/**
 * One operation answering `200` with this schema, in memory.
 */
function answeringWithSchema(Schema $schema, string $operationId = 'showThing'): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/'.$operationId),
        operationId: $operationId,
        responses: [new Response('200', ['application/json' => $schema])],
    );
}

/**
 * A named object schema, written as a component.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 */
function responseComponent(string $name, array $properties, array $required = [], bool $nullable = false): Schema
{
    return new Schema(
        name: $name,
        source: '#/components/schemas/'.$name,
        types: $nullable ? [SchemaType::Object, SchemaType::Null] : [SchemaType::Object],
        properties: $properties,
        required: $required,
    );
}

function responseString(): Schema
{
    return new Schema(types: [SchemaType::String]);
}

// No depth: `x-max-depth` limits what a request validates, and a response DTO validates nothing, so the class is the
// class it points back at at every level.
it('types a recursive response schema as its own class', function (Schema $comment, bool $list, bool $nullable, bool $optional): void {
    $dtos = (new ResponseDtoPlanner)->plan([answeringWithSchema($comment)])->dtos;
    $dto = $dtos[0];
    $property = array_values(array_filter($dto->properties, static fn (InputDtoProperty $property): bool => $property->key === 'link'))[0];

    expect($property->optional)->toBe($optional)
        ->and($property->type->kind)->toBe($list ? InputDtoType::LIST : InputDtoType::DTO);

    $target = $list ? $property->type->item : $property->type;

    expect($target?->kind)->toBe(InputDtoType::DTO)
        ->and($target?->class)->toBe($comment->name.'Dto')
        ->and($target?->nullable)->toBe($nullable)
        ->and(implode("\n", $dto->findings))->not->toContain('points back')
        // Nothing generated twice.
        ->and(array_map(static fn (PlannedResponseDto $dto): string => $dto->shortName, $dtos))->toBe([$comment->name.'Dto']);
})->with([
    'itself' => [
        responseComponent('Address', ['street' => responseString(), 'link' => Schema::recursion('#/components/schemas/Address', 'Address')], ['street']),
        false, false, true,
    ],
    'a list of itself' => [
        responseComponent('Comment', [
            'text' => responseString(),
            'link' => new Schema(types: [SchemaType::Array], items: Schema::recursion('#/components/schemas/Comment', 'Comment')),
        ], ['text']),
        true, false, true,
    ],
    'itself, nullable' => [
        responseComponent('Node', ['label' => responseString(), 'link' => Schema::recursion('#/components/schemas/Node', 'Node')], ['label'], nullable: true),
        false, true, true,
    ],
    'itself, required and stating a depth of one' => [
        new Schema(
            name: 'Chain',
            source: '#/components/schemas/Chain',
            types: [SchemaType::Object],
            properties: ['label' => responseString(), 'link' => Schema::recursion('#/components/schemas/Chain', 'Chain')],
            required: ['label', 'link'],
            maxDepth: 1,
        ),
        false, false, false,
    ],
]);

// A marker with no named schema to point at has no class to be built into, and the file says so in its own words: a
// response is not something a request validated.
it('reads a recursion with no class to point at as mixed and does not blame the request', function (Schema $schema, string $says): void {
    $dtos = (new ResponseDtoPlanner)->plan([answeringWithSchema($schema)])->dtos;
    $owners = array_values(array_filter($dtos, static fn (PlannedResponseDto $dto): bool => str_contains(implode("\n", $dto->findings), $says)));

    expect($owners)->toHaveCount(1);

    $link = array_values(array_filter($owners[0]->properties, static fn (InputDtoProperty $property): bool => $property->key === 'link'))[0];

    expect($link->type->kind)->toBe(InputDtoType::MIXED)
        ->and(implode("\n", $owners[0]->findings))->not->toContain('request');
})->with([
    'an inline schema has no name to be found by' => [
        new Schema(types: [SchemaType::Object], properties: [
            'link' => Schema::recursion('#/paths/~1showThing/get/responses/200/content/application~1json/schema'),
        ]),
        '`link` points back at `#/paths/~1showThing/get/responses/200/content/application~1json/schema`, which is not a single named schema',
    ],
    'properties written beside the marker' => [
        responseComponent('Node', [
            'label' => responseString(),
            'link' => new Schema(
                name: 'Node',
                types: [SchemaType::Object],
                properties: ['extra' => responseString()],
                recursesTo: '#/components/schemas/Node',
            ),
        ]),
        '`link` writes properties beside the reference, which the class of `Node` does not carry',
    ],
    'a target that is not an object with properties' => [
        responseComponent('Shelf', ['link' => new Schema(
            name: 'Forest',
            source: '#/components/schemas/Forest',
            types: [SchemaType::Array],
            items: new Schema(types: [SchemaType::Object], properties: [
                'link' => Schema::recursion('#/components/schemas/Forest', 'Forest'),
            ]),
        )]),
        '`link` points back at `#/components/schemas/Forest`, which is not an object with properties',
    ],
]);

// A property only a request sends is not in what comes back.
it('leaves a writeOnly property out of the response DTO', function (): void {
    $dto = (new ResponseDtoPlanner)->plan([answeringWithSchema(responseComponent('Account', [
        'email' => responseString(),
        'password' => new Schema(types: [SchemaType::String], writeOnly: true),
        'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
    ], ['email', 'password', 'id']))])->dtos[0];

    expect(array_map(static fn (InputDtoProperty $property): string => $property->key, $dto->properties))->toBe(['email', 'id'])
        ->and(implode("\n", $dto->findings))->toContain('`password` is `writeOnly`, so a response never sends it and this type has no property for it')
        ->and(implode("\n", $dto->findings))->not->toContain('`id`');
});

// The two reasons a status has no DTO for want of a body: nothing to read it from, and nothing JSON to read.
it('gives no DTO to a response with no JSON body', function (string $operationId, array $dtos, string $reason): void {
    $none = array_values(array_filter(statusesOf($operationId), static fn (StatusResponse $status): bool => $status->dto === null));

    // The other statuses of the operation keep their DTOs: only the status with no body goes without.
    expect(array_keys(statusDtos($operationId)))->toBe($dtos)
        ->and($none)->toHaveCount(1)
        ->and($none[0]->reason)->toBe($reason)
        ->and($none[0]->sentence())->toBe('The `'.$none[0]->status.'` response has no DTO: '.$reason.'.');
})->with([
    'a 204' => ['updateUser', ['UserDto', 'ValidationErrorDto'], 'it declares no body'],
    'only text/csv' => ['exportUsers', [], 'it declares no JSON media type'],
]);

// A client picks the representation with `Accept`, and a DTO names one shape: a status with two JSON schemas is not
// given one of them, and the build goes on.
it('gives no DTO to a status with two JSON schemas and says why', function (): void {
    $statuses = statusesOfFixture('response-two-json-schemas.yaml', 'showVersions');
    $dtos = dtosOfFixture('response-two-json-schemas.yaml', 'showVersions');

    expect($statuses[0]->dto)->toBeNull()
        ->and($statuses[0]->sentence())->toBe(
            'The `200` response has no DTO: it declares two JSON media types with different schemas '
            .'(`application/vnd.acme.v1+json` and `application/vnd.acme.v2+json`).',
        )
        // The same schema under two media types is the one DTO (and a hostile media type cannot close the comment).
        ->and($statuses[1]->dto)->toBe('ProblemDto')
        ->and(array_keys($dtos))->toBe(['ProblemDto'])
        ->and($dtos['ProblemDto']->readers)->toBe([['label' => 'get /versions', 'status' => '422']]);
});

// The keys of a property-less object are the data, so the DTO carries them whole under the one property it has, as a
// request body does (and a response has no `query` parameter, so the values are always typed).
it('carries a property-less response object whole under additionalProperties', function (string $operationId, string $class, string $doc, ?string $value): void {
    $dtos = dtosOfFixture('response-maps.yaml', $operationId);
    $dto = $dtos[$class];
    $property = $dto->properties[0];

    expect($dto->properties)->toHaveCount(1)
        ->and($property->key)->toBe('additionalProperties')
        ->and($property->name)->toBe('additionalProperties')
        ->and($property->wholePayload)->toBeTrue()
        ->and($property->optional)->toBeFalse()
        ->and($property->type->kind)->toBe(InputDtoType::MAP)
        ->and($property->type->doc())->toBe($doc)
        // Never a finding about the values: nothing a response shares the payload with could have left them unchecked.
        ->and(implode("\n", $dto->findings))->not->toContain('`mixed`')
        ->and(array_keys($dtos))->toBe($value === null ? [$class] : [$class, $value]);
})->with([
    'an inline root map' => ['createUser', 'CreateUser201ResponseDto', 'array<array-key, int>', null],
    'a root map behind a component' => ['showCounts', 'CountsDto', 'array<array-key, int>', null],
    'an open object' => ['showOpen', 'ShowOpen200ResponseDto', 'array<array-key, mixed>', null],
    'additionalProperties of anything' => ['showAny', 'ShowAny200ResponseDto', 'array<array-key, mixed>', null],
    'a root map of inline objects' => [
        'showGroups',
        'ShowGroups200ResponseDto',
        'array<array-key, ShowGroups200ResponseAdditionalPropertiesValueDto>',
        'ShowGroups200ResponseAdditionalPropertiesValueDto',
    ],
]);

it('types the values of a nested map and of a list of maps through the class of each value', function (): void {
    $team = dtosOfFixture('response-maps.yaml', 'showTeam');
    $labels = array_values(array_filter($team['ShowTeam200ResponseDto']->properties, static fn (InputDtoProperty $property): bool => $property->key === 'labels'))[0];

    expect($labels->type->doc())->toBe('array<array-key, LabelDto>')
        ->and($labels->wholePayload)->toBeFalse()
        ->and($labels->optional)->toBeTrue()
        ->and(array_keys($team))->toBe(['ShowTeam200ResponseDto', 'LabelDto']);

    $statuses = statusesOfFixture('response-maps.yaml', 'listCounts');

    expect(array_keys(dtosOfFixture('response-maps.yaml', 'listCounts')))->toBe(['CountsDto'])
        ->and($statuses[0]->depth)->toBe(1);
});

// Values of the other keys beside declared properties are not carried, and the file says so.
it('carries only the declared properties beside an additionalProperties schema and says the rest are not', function (): void {
    $pet = dtosOfFixture('response-maps.yaml', 'showPet')['PetDto'];

    expect(array_map(static fn (InputDtoProperty $property): string => $property->key, $pet->properties))->toBe(['name'])
        ->and(implode("\n", $pet->findings))->toContain('`additionalProperties` types the values of the keys that are not declared, but this type carries only the declared properties');
});

// A schema that is its type or `null` is its type: the DTO cannot be `null`, so the status says what may be, which is
// what the controller's finding reads. A list of lists is said as one, and `null` is the body's only when the body is.
it('plans a nullable response root as its type and says what may be null', function (Closure $schema, int $depth, string $sentence): void {
    $plan = (new ResponseDtoPlanner)->plan([answeringWithSchema($schema())]);
    $status = $plan->statuses['get /showThing'][0];

    expect($status->dto)->toBe('ThingDto')
        ->and($status->depth)->toBe($depth)
        ->and($status->sentence())->toBe($sentence);
})->with([
    'an object or null' => [
        static fn (): Schema => responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])], nullable: true),
        0,
        'The `200` response is `ThingDto`, in `Data/ThingDto.php`, or `null`, which no DTO holds.',
    ],
    'a list' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array],
            items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])]),
        ),
        1,
        'The `200` response is a list of `ThingDto`, in `Data/ThingDto.php`.',
    ],
    'a list of lists' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array],
            items: new Schema(
                types: [SchemaType::Array],
                items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])]),
            ),
        ),
        2,
        'The `200` response is a list of lists of `ThingDto`, in `Data/ThingDto.php`.',
    ],
    'a list of lists, the inner one or null' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array],
            items: new Schema(
                types: [SchemaType::Array, SchemaType::Null],
                items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])]),
            ),
        ),
        2,
        'The `200` response is a list of lists of `ThingDto`, in `Data/ThingDto.php`, where an item may be `null`, which no DTO holds.',
    ],
    'a list of objects or null' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array],
            items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])], nullable: true),
        ),
        1,
        'The `200` response is a list of `ThingDto`, in `Data/ThingDto.php`, where an item may be `null`, which no DTO holds.',
    ],
    'a list or null' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array, SchemaType::Null],
            items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])]),
        ),
        1,
        'The `200` response is a list of `ThingDto`, in `Data/ThingDto.php`, or `null`, which no DTO holds.',
    ],
    'a list or null, of objects or null' => [
        static fn (): Schema => new Schema(
            types: [SchemaType::Array, SchemaType::Null],
            items: responseComponent('Thing', ['id' => new Schema(types: [SchemaType::Integer])], nullable: true),
        ),
        1,
        'The `200` response is a list of `ThingDto`, in `Data/ThingDto.php`, or `null`, where an item may be `null` too, which no DTO holds.',
    ],
]);
