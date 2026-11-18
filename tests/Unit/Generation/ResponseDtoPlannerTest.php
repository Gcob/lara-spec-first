<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\InputDtoProperty;
use Gcob\LaraSpecFirst\Generation\InputDtoType;
use Gcob\LaraSpecFirst\Generation\PlannedResponseDto;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;
use Gcob\LaraSpecFirst\Generation\ResponseFallback;
use Gcob\LaraSpecFirst\Generation\ResponseFallbackCause;
use Gcob\LaraSpecFirst\Generation\ResponsePlan;
use Gcob\LaraSpecFirst\Generation\StatusResponse;
use Gcob\LaraSpecFirst\Generation\UntypedResponseCause;
use Gcob\LaraSpecFirst\Generation\UntypedResponseValue;

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

/**
 * One document's operations in the order it writes them, or the other way round.
 *
 * @param  list<Operation>  $operations
 * @return list<Operation>
 */
function writtenInOrder(array $operations, bool $reversed): array
{
    return $reversed ? array_reverse($operations) : $operations;
}

/**
 * The DTOs of a plan by class name, in one order whichever the build reached first, so two plans can be compared.
 *
 * @return array<string, PlannedResponseDto>
 */
function dtosByName(ResponsePlan $plan): array
{
    $dtos = array_column($plan->dtos, null, 'shortName');
    ksort($dtos);

    return $dtos;
}

/**
 * The orders a contested name is decided in: what the document writes first must not change the answer.
 *
 * @return array<string, array{bool}>
 */
function documentOrders(): array
{
    return ['in the order the document writes' => [false], 'reversed' => [true]];
}

// A response schema written inline takes its class name from where it sits, which nobody chose, and a component's name
// is the one its author chose: the component keeps the class, and the inline schema gives way, in either order.
it("gives an inline response schema's class name to the component that claims it", function (bool $reversed): void {
    $operations = extractFixture('response-inline-meets-component.yaml');
    $plan = (new ResponseDtoPlanner)->plan(writtenInOrder($operations, $reversed), []);
    $dtos = dtosByName($plan);
    $address = array_column($dtos['UserDto']->properties, null, 'key')['address'];
    $inline = '#/components/schemas/User/properties/address';

    expect(array_keys($dtos))->toBe(['UserAddressDto', 'UserDto'])
        ->and($dtos['UserAddressDto']->position)->toBe('#/components/schemas/UserAddress')
        ->and($address->type->kind)->toBe(InputDtoType::MIXED)
        ->and($dtos['UserDto']->findings)->toContain(
            '`address` is `mixed`: its schema (written inline at `'.$inline.'`) derives the class name `UserAddressDto`, '
            .'which the schema `UserAddress` (`#/components/schemas/UserAddress`) claims. Give it a component name of '
            .'its own to type it.',
        )
        ->and(fallbacksOf($plan))->toBe([[$inline, $inline, ResponseFallbackCause::NameTaken]])
        ->and(dtosByName((new ResponseDtoPlanner)->plan(writtenInOrder($operations, ! $reversed), [])))->toEqual($dtos);
})->with(documentOrders());

// Two components that derive one class name are two equals: whichever the document writes first, neither has the DTO,
// and both say why with the two positions in the same order.
it('gives no DTO to two response components that claim one class name', function (bool $reversed): void {
    $operations = extractFixture('response-two-components-one-name.yaml');
    $plan = (new ResponseDtoPlanner)->plan(writtenInOrder($operations, $reversed), []);
    $says = 'The `200` response has no DTO: the schema `NewUser` (`#/components/schemas/NewUser`) and the schema `new-user` '
        .'(`#/components/schemas/new-user`) both derive the class name `NewUserDto`, so neither has it. Rename one of them.';

    expect($plan->dtos)->toBe([])
        ->and($plan->statuses['get /hyphenated'][0]->sentence())->toBe($says)
        ->and($plan->statuses['get /studly'][0]->sentence())->toBe($says);
})->with(documentOrders());

// A component reached as a property is as much a claim as one an operation returns: three uses of the two names give
// each use the same answer, and the object that holds them keeps its DTO.
it('gives no DTO to two response components that claim one class name when they are reached as properties', function (bool $reversed): void {
    $hyphenated = responseComponent('new-user', ['email' => responseString()]);
    $studly = responseComponent('NewUser', ['name' => responseString()]);
    $holder = new Schema(types: [SchemaType::Object], properties: [
        'first' => $hyphenated,
        'second' => $studly,
        'tags' => new Schema(types: [SchemaType::Array], items: $studly),
    ]);
    $plan = (new ResponseDtoPlanner)->plan(writtenInOrder([answeringWithSchema($holder)], $reversed), []);
    $properties = array_column($plan->dtos[0]->properties, null, 'key');

    expect(array_column($plan->dtos, 'shortName'))->toBe(['ShowThing200ResponseDto'])
        ->and($properties['first']->type->kind)->toBe(InputDtoType::MIXED)
        ->and($properties['second']->type->kind)->toBe(InputDtoType::MIXED)
        ->and($properties['tags']->type->kind)->toBe(InputDtoType::LIST)
        ->and($properties['tags']->type->item?->kind)->toBe(InputDtoType::MIXED);
})->with(documentOrders());

/**
 * An object written inline, with no name of its own.
 *
 * @param  array<string, Schema>  $properties
 */
function inlineObject(array $properties): Schema
{
    return new Schema(types: [SchemaType::Object], properties: $properties);
}

// Two inline objects of different shapes that derive one class name are two equals too, so both properties are `mixed`.
// Two that say the same thing are one class, as they always were.
it('gives no DTO to two inline response schemas that derive one class name', function (bool $reversed): void {
    $differently = (new ResponseDtoPlanner)->plan(writtenInOrder([
        answeringWithSchema(responseComponent('Team', ['leadUser' => inlineObject(['name' => responseString()])]), 'showTeam'),
        answeringWithSchema(responseComponent('TeamLead', ['user' => inlineObject(['email' => responseString()])]), 'showLead'),
    ], $reversed), []);
    $alike = (new ResponseDtoPlanner)->plan(writtenInOrder([
        answeringWithSchema(responseComponent('Team', ['leadUser' => inlineObject(['name' => responseString()])]), 'showTeam'),
        answeringWithSchema(responseComponent('TeamLead', ['user' => inlineObject(['name' => responseString()])]), 'showLead'),
    ], $reversed), []);
    $team = dtosByName($differently)['TeamDto'];
    $lead = dtosByName($differently)['TeamLeadDto'];
    $shared = dtosByName($alike);

    expect(array_keys(dtosByName($differently)))->toBe(['TeamDto', 'TeamLeadDto'])
        ->and($team->properties[0]->type->kind)->toBe(InputDtoType::MIXED)
        ->and($lead->properties[0]->type->kind)->toBe(InputDtoType::MIXED)
        ->and($team->findings)->toContain(
            '`leadUser` is `mixed`: the inline schema written at `#/components/schemas/Team/properties/leadUser` and '
            .'the inline schema written at `#/components/schemas/TeamLead/properties/user` both derive the class name '
            .'`TeamLeadUserDto`, so neither has it. Rename one of them.',
        )
        ->and(array_keys($shared))->toBe(['TeamDto', 'TeamLeadDto', 'TeamLeadUserDto'])
        ->and($shared['TeamDto']->properties[0]->type->class)->toBe('TeamLeadUserDto')
        ->and($shared['TeamLeadDto']->properties[0]->type->class)->toBe('TeamLeadUserDto');
})->with(documentOrders());

/**
 * A component whose `allOf` branches disagree about the type of `id`.
 */
function disagreeingComponent(string $name): Schema
{
    $branch = static fn (SchemaType $type): Schema => new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [$type])]);

    return new Schema(name: $name, source: '#/components/schemas/'.$name, allOf: [$branch(SchemaType::Integer), $branch(SchemaType::String)]);
}

// A name is contested by every schema a response reaches, not by the ones a walk met before the clash: a schema that
// loses its DTO still holds a property that claims a name, and what is claimed does not depend on the order.
it('decides a contested name from every schema a response reaches', function (Closure $operations, Closure $says, bool $reversed): void {
    $plan = (new ResponseDtoPlanner)->plan(writtenInOrder($operations(), $reversed), []);

    $says($plan, dtosByName($plan));
})->with([
    'a schema that gives way holds a property that clashes' => [
        // `address` loses `UserAddressDto` to the component, and holds `new-pet`, which clashes with `NewPet`: the clash
        // is decided all the same, so neither pet has a DTO.
        static fn (): array => [
            answeringWithSchema(responseComponent('User', ['address' => inlineObject([
                'street' => responseString(),
                'pet' => responseComponent('new-pet', ['a' => responseString()]),
            ])]), 'showUser'),
            answeringWithSchema(responseComponent('UserAddress', ['street' => responseString()]), 'showAddress'),
            answeringWithSchema(responseComponent('NewPet', ['b' => responseString()]), 'showPet'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            expect(array_keys($dtos))->toBe(['UserAddressDto', 'UserDto'])
                ->and($plan->statuses['get /showPet'][0]->sentence())->toContain('both derive the class name `NewPetDto`, so neither has it');
        },
    ],
    'a component whose allOf branches disagree still claims its name' => [
        static fn (): array => [
            answeringWithSchema(responseComponent('User', ['address' => inlineObject(['street' => responseString()])]), 'showUser'),
            answeringWithSchema(disagreeingComponent('UserAddress'), 'showAddress'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            $address = array_column($dtos['UserDto']->properties, null, 'key')['address'];

            expect(array_keys($dtos))->toBe(['UserDto'])
                ->and($address->type->kind)->toBe(InputDtoType::MIXED)
                ->and($dtos['UserDto']->findings)->toContain(
                    '`address` is `mixed`: its schema (written inline at `#/components/schemas/User/properties/address`) '
                    .'derives the class name `UserAddressDto`, which the schema `UserAddress` (`#/components/schemas/UserAddress`) '
                    .'claims, and that schema gets no DTO either. Give it a component name of its own to type it.',
                )
                ->and($plan->statuses['get /showAddress'][0]->sentence())->toContain('its `allOf` branches disagree at `id`');
        },
    ],
    'an inline schema whose allOf branches disagree claims nothing' => [
        static fn (): array => [
            answeringWithSchema(responseComponent('User', ['address' => disagreeingBranches()]), 'showUser'),
            answeringWithSchema(responseComponent('UserAddress', ['street' => responseString()]), 'showAddress'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            expect(array_keys($dtos))->toBe(['UserAddressDto', 'UserDto'])
                ->and($dtos['UserAddressDto']->position)->toBe('#/components/schemas/UserAddress')
                ->and($dtos['UserDto']->findings)->toContain(
                    '`address` is `mixed`: its `allOf` branches disagree at `id`: one branch is `integer` and another is `string`. Make them agree.',
                )
                ->and(array_column($plan->fallbacks, 'cause'))->toBe([ResponseFallbackCause::AllOfDisagrees]);
        },
    ],
    'a component whose name leaves no class name never has its properties walked' => [
        // `new-pet` is reached only through `123`, which has no class name and so no DTO to hold it: it never meets
        // `NewPet`, which keeps its DTO.
        static fn (): array => [
            answeringWithSchema(responseComponent('123', ['pet' => responseComponent('new-pet', ['a' => responseString()])]), 'showCode'),
            answeringWithSchema(responseComponent('NewPet', ['b' => responseString()]), 'showPet'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            expect(array_keys($dtos))->toBe(['NewPetDto'])
                ->and($plan->statuses['get /showPet'][0]->sentence())->toContain('is `NewPetDto`')
                ->and(array_column($plan->fallbacks, 'cause'))->toBe([ResponseFallbackCause::NameUnusable]);
        },
    ],
    'a component whose keys collide still claims its name' => [
        static fn (): array => [
            answeringWithSchema(responseComponent('User', ['address' => inlineObject(['street' => responseString()])]), 'showUser'),
            answeringWithSchema(responseComponent('UserAddress', ['user-id' => responseString(), 'user_id' => responseString()]), 'showAddress'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            expect(array_keys($dtos))->toBe(['UserDto'])
                ->and($dtos['UserDto']->findings)->toContain(
                    '`address` is `mixed`: its schema (written inline at `#/components/schemas/User/properties/address`) '
                    .'derives the class name `UserAddressDto`, which the schema `UserAddress` (`#/components/schemas/UserAddress`) '
                    .'claims, and that schema gets no DTO either. Give it a component name of its own to type it.',
                )
                ->and($plan->statuses['get /showAddress'][0]->sentence())->toContain('both derive the property `$user_id`');
        },
    ],
    'a string enumeration never claims the name an inline object derives' => [
        // `OrderStatus` would never be a DTO, so it claims nothing, and `Order.status` is still the class it was.
        static fn (): array => [
            answeringWithSchema(responseComponent('Order', ['status' => inlineObject(['code' => responseString()])]), 'showOrder'),
            answeringWithSchema(responseComponent('Delivery', ['state' => new Schema(
                name: 'OrderStatus',
                source: '#/components/schemas/OrderStatus',
                types: [SchemaType::String],
                enum: ['open', 'closed'],
            )]), 'showDelivery'),
            answeringWithSchema(new Schema(
                name: 'OrderStatusList',
                source: '#/components/schemas/OrderStatusList',
                types: [SchemaType::Array],
                items: new Schema(name: 'OrderStatus', source: '#/components/schemas/OrderStatus', types: [SchemaType::String], enum: ['open', 'closed']),
            ), 'listStatuses'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            expect(array_keys($dtos))->toBe(['DeliveryDto', 'OrderDto', 'OrderStatusDto'])
                ->and($dtos['OrderStatusDto']->position)->toBe('#/components/schemas/Order/properties/status')
                ->and($plan->fallbacks)->toBe([]);
        },
    ],
    'a scalar component with an unusable name is never checked' => [
        // `2faCode` would never be a DTO, so its name is never read: `code` keeps its type.
        static fn (): array => [
            answeringWithSchema(inlineObject(['code' => new Schema(name: '2faCode', source: '#/components/schemas/2faCode', types: [SchemaType::String])]), 'showSession'),
            answeringWithSchema(new Schema(name: '2faCode', source: '#/components/schemas/2faCode', types: [SchemaType::String]), 'showCode'),
        ],
        function (ResponsePlan $plan, array $dtos): void {
            $code = array_column($dtos['ShowSession200ResponseDto']->properties, null, 'key')['code'];

            expect($code->type->kind)->toBe(InputDtoType::STRING)
                ->and($code->optional)->toBeTrue()
                ->and($plan->fallbacks)->toBe([])
                ->and($plan->statuses['get /showCode'][0]->sentence())->toContain('its body is not an object or a list');
        },
    ],
])->with(documentOrders());

// A response enforces nothing, so branches that disagree cost the status its DTO and never the build, and the sentence
// is for the reader of the controller, not for a rule set.
it('gives no DTO to a response schema whose allOf branches disagree, and says why in its own words', function (): void {
    $branch = static fn (SchemaType $type): Schema => new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [$type])]);
    $plan = (new ResponseDtoPlanner)->plan([answeringWithSchema(
        new Schema(name: 'Thing', source: '#/components/schemas/Thing', allOf: [$branch(SchemaType::Integer), $branch(SchemaType::String)]),
    )], []);

    expect($plan->dtos)->toBe([])
        ->and($plan->statuses['get /showThing'][0]->sentence())->toBe(
            'The `200` response has no DTO: its `allOf` branches disagree at `id`: one branch is `integer` and another is `string`. '
            .'Make them agree.',
        );
});

// In 3.0 a keyword written beside a `$ref` is ignored, so what 3.1 reads as branches that disagree is no conflict here:
// the status has the referenced class, and `id` keeps the type the component gives it.
it('gives a status the referenced class when a 3.0 response writes a sibling beside its reference', function (): void {
    $plan = (new ResponseDtoPlanner)->plan(extractFixture('response-ref-sibling-conflict-3.0.yaml'), []);

    expect(array_column($plan->dtos, 'shortName'))->toBe(['BaseDto'])
        ->and($plan->dtos[0]->properties[0]->type->kind)->toBe(InputDtoType::INT)
        ->and($plan->statuses['get /siblings'][0]->sentence())->toContain('is `BaseDto`')
        ->and($plan->fallbacks)->toBe([]);
});

// Two operations that return one schema are one type, whatever status each answers with.
it('shares one response DTO between operations returning one schema', function (): void {
    $dtos = (new ResponseDtoPlanner)->plan([
        answeringWith('showUser', 'User', '#/components/schemas/User'),
        answeringWith('showMember', 'User', '#/components/schemas/User'),
        answeringWith('createUser', 'User', '#/components/schemas/User', '201'),
    ], [])->dtos;

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

    return array_column((new ResponseDtoPlanner)->plan($operations, [])->dtos, null, 'shortName');
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

    return (new ResponseDtoPlanner)->plan([$operation], [])->statuses[$operation->label()];
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

    expect(array_column((new ResponseDtoPlanner)->plan([$operation], [])->dtos, 'shortName'))
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

    return array_column((new ResponseDtoPlanner)->plan([$operation], [])->dtos, null, 'shortName');
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

    return (new ResponseDtoPlanner)->plan([$operation], [])->statuses[$operation->label()];
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
    $dtos = (new ResponseDtoPlanner)->plan([answeringWithSchema($comment)], [])->dtos;
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
    $dtos = (new ResponseDtoPlanner)->plan([answeringWithSchema($schema)], [])->dtos;
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
    ], ['email', 'password', 'id']))], [])->dtos[0];

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
    $plan = (new ResponseDtoPlanner)->plan([answeringWithSchema($schema())], []);
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

/**
 * `allOf` branches that disagree about the type of `id`.
 */
function disagreeingBranches(): Schema
{
    $branch = static fn (SchemaType $type): Schema => new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [$type])]);

    return new Schema(allOf: [$branch(SchemaType::Integer), $branch(SchemaType::String)]);
}

// One property that cannot be typed costs that property and nothing else: the parent keeps its DTO and every other
// type, and the innermost value is the one that is `mixed`.
it('types mixed a response value whose schema cannot be typed and keeps the others', function (Schema $value, string $kind, ?string $item, string $says): void {
    $plan = (new ResponseDtoPlanner)->plan([answeringWithSchema(responseComponent('Team', [
        'name' => responseString(),
        'id' => new Schema(types: [SchemaType::Integer]),
        'x' => $value,
    ], ['name', 'id']))], []);
    $properties = array_column($plan->dtos[0]->properties, null, 'key');

    expect(array_column($plan->dtos, 'shortName'))->toBe(['TeamDto'])
        ->and($properties['name']->type->kind)->toBe(InputDtoType::STRING)
        ->and($properties['id']->type->kind)->toBe(InputDtoType::INT)
        ->and($properties['x']->optional)->toBeTrue()
        ->and($properties['x']->type->kind)->toBe($kind)
        ->and($properties['x']->type->item?->kind)->toBe($item)
        ->and(implode("\n", $plan->dtos[0]->findings))->toContain($says);
})->with([
    'a name that leaves no class name' => [
        new Schema(name: '123', source: '#/components/schemas/123', types: [SchemaType::Object], properties: ['a' => new Schema(types: [SchemaType::String])]),
        InputDtoType::MIXED,
        null,
        '`x` is `mixed`: the schema name `123` leaves no class name. Rename it to start with a letter or an underscore.',
    ],
    'two keys deriving one property' => [
        new Schema(types: [SchemaType::Object], properties: [
            'user-id' => new Schema(types: [SchemaType::String]),
            'user_id' => new Schema(types: [SchemaType::Integer]),
        ]),
        InputDtoType::MIXED,
        null,
        '`x` is `mixed`: its keys `user-id` and `user_id` both derive the property `$user_id`. Rename one of them.',
    ],
    'allOf branches that disagree' => [
        disagreeingBranches(),
        InputDtoType::MIXED,
        null,
        '`x` is `mixed`: its `allOf` branches disagree at `id`: one branch is `integer` and another is `string`. Make them agree.',
    ],
    'a type no branch shares' => [
        new Schema(allOf: [new Schema(types: [SchemaType::Integer]), new Schema(types: [SchemaType::String])]),
        InputDtoType::MIXED,
        null,
        '`x` is `mixed`: its `allOf` branches disagree: one branch is `integer` and another is `string`. Make them agree.',
    ],
    'a list whose items disagree' => [
        new Schema(types: [SchemaType::Array], items: disagreeingBranches()),
        InputDtoType::LIST,
        InputDtoType::MIXED,
        '`xItem` is `mixed`: its `allOf` branches disagree at `id`',
    ],
    'a map whose values disagree' => [
        new Schema(types: [SchemaType::Object], additionalPropertiesSchema: disagreeingBranches()),
        InputDtoType::MAP,
        InputDtoType::MIXED,
        '`xValue` is `mixed`: its `allOf` branches disagree at `id`',
    ],
]);

/**
 * Where each fallback of a plan lost a type, the schema that gave way, and why.
 *
 * @return list<array{0: string, 1: string, 2: ResponseFallbackCause}>
 */
function fallbacksOf(ResponsePlan $plan): array
{
    return array_map(
        static fn (ResponseFallback $fallback): array => [$fallback->where, $fallback->position, $fallback->cause],
        $plan->fallbacks,
    );
}

// What a program reads, beside the sentence a person reads: each status or value that lost its type to a schema's own
// fault, under the cause of that fault.
it('records each response fallback under a cause of its own', function (string $fixture, array $expected, array $inputs): void {
    expect(fallbacksOf((new ResponseDtoPlanner)->plan(extractFixture($fixture), $inputs)))->toBe($expected);
})->with([
    'two keys deriving one property' => [
        'response-keys-collide.yaml',
        [[
            '#/paths/~1users/get/responses/200/content/application~1json/schema',
            '#/paths/~1users/get/responses/200/content/application~1json/schema',
            ResponseFallbackCause::KeysCollide,
        ]],
        [],
    ],
    'allOf branches that disagree' => [
        'response-allof-disagrees.yaml',
        [
            [
                '#/paths/~1things/get/responses/200/content/application~1json/schema',
                '#/paths/~1things/get/responses/200/content/application~1json/schema',
                ResponseFallbackCause::AllOfDisagrees,
            ],
            [
                '#/paths/~1siblings/get/responses/200/content/application~1json/schema',
                '#/paths/~1siblings/get/responses/200/content/application~1json/schema',
                ResponseFallbackCause::AllOfDisagrees,
            ],
        ],
        [],
    ],
    'a name that leaves no class name' => [
        'response-name-unusable.yaml',
        [
            [
                '#/paths/~1codes/get/responses/200/content/application~1json/schema',
                '#/components/schemas/123',
                ResponseFallbackCause::NameUnusable,
            ],
            [
                '#/paths/~1others/get/responses/200/content/application~1json/schema',
                '#/components/schemas/456',
                ResponseFallbackCause::NameUnusable,
            ],
        ],
        [],
    ],
    'a property' => [
        'response-property-gives-way.yaml',
        [[
            '#/components/schemas/Team/properties/owner',
            '#/components/schemas/Team/properties/owner',
            ResponseFallbackCause::KeysCollide,
        ]],
        [],
    ],
    'a class name a component claims' => [
        'response-inline-meets-component.yaml',
        [[
            '#/components/schemas/User/properties/address',
            '#/components/schemas/User/properties/address',
            ResponseFallbackCause::NameTaken,
        ]],
        [],
    ],
    'two components claiming one class name' => [
        'response-two-components-one-name.yaml',
        [
            [
                '#/paths/~1hyphenated/get/responses/200/content/application~1json/schema',
                '#/components/schemas/new-user',
                ResponseFallbackCause::NameTaken,
            ],
            [
                '#/paths/~1studly/get/responses/200/content/application~1json/schema',
                '#/components/schemas/NewUser',
                ResponseFallbackCause::NameTaken,
            ],
        ],
        [],
    ],
    'a class name an input DTO takes' => [
        'response-name-taken-by-input.yaml',
        [[
            '#/paths/~1users~1{id}/put/responses/200/content/application~1json/schema',
            '#/components/schemas/UserInput',
            ResponseFallbackCause::NameTaken,
        ]],
        ['UserInputDto' => '#/components/schemas/User'],
    ],
]);

// What a DTO cannot type is not a fault of the schema's own, so it has no record of this kind: a keyword, a recursion
// with no class, a status with no body, a status with two JSON schemas.
it('leaves a response fallback unrecorded when the schema is not at fault', function (string $fixture): void {
    expect((new ResponseDtoPlanner)->plan(extractFixture($fixture), [])->fallbacks)->toBe([]);
})->with([
    'a oneOf property' => ['doctor-ignored-keyword-in-response.yaml'],
    'a 204 and a validation error' => ['response-statuses.yaml'],
    'two JSON schemas under one status' => ['response-two-json-schemas.yaml'],
]);

it('leaves a response fallback unrecorded for a property with no type, a union and a recursion with no class', function (Schema $value): void {
    $plan = (new ResponseDtoPlanner)->plan([answeringWithSchema(responseComponent('Team', ['x' => $value]))], []);

    expect($plan->fallbacks)->toBe([])
        ->and($plan->dtos[0]->properties[0]->type->kind)->toBe(InputDtoType::MIXED);
})->with([
    'no type' => [new Schema],
    'a union of types' => [new Schema(types: [SchemaType::String, SchemaType::Integer])],
    'a recursion with no class' => [Schema::recursion('#/components/schemas/Nowhere')],
]);

/**
 * Each value of a plan a DTO types `mixed` for what the contract says, as the DTO that holds it, the cause and where
 * the value is written.
 *
 * @return list<array{0: string, 1: UntypedResponseCause, 2: string}>
 */
function untypedOf(ResponsePlan $plan): array
{
    return array_map(
        static fn (UntypedResponseValue $value): array => [$value->owner, $value->cause, $value->position],
        $plan->untyped,
    );
}

/**
 * One operation answering with a component `Team` that holds this value under `x`.
 */
function teamHolding(Schema $value): Operation
{
    return answeringWithSchema(responseComponent('Team', ['x' => $value]));
}

// A program reads this beside the sentence a person reads: which value the DTO types `mixed` because of what the
// contract says, and why, so the doctor can tell it from a value that is `mixed` because a schema was at fault.
it('records why a response DTO types a value mixed', function (Closure $operations, string $owner, UntypedResponseCause $cause, string $position, string $says): void {
    $plan = (new ResponseDtoPlanner)->plan($operations(), []);
    $dto = array_column($plan->dtos, null, 'shortName')[$owner];

    expect(untypedOf($plan))->toBe([[$owner, $cause, $position]])
        ->and(implode("\n", $dto->findings))->toContain($says)
        ->and($plan->fallbacks)->toBe([]);
})->with([
    'a type union' => [
        static fn (): array => [teamHolding(new Schema(types: [SchemaType::String, SchemaType::Integer]))],
        'TeamDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/Team/properties/x',
        '`x` states more than one type, so it is `mixed`.',
    ],
    'no type' => [
        static fn (): array => [teamHolding(new Schema)],
        'TeamDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/Team/properties/x',
        '`x` states no type, so it is `mixed`.',
    ],
    'only null' => [
        static fn (): array => [teamHolding(new Schema(types: [SchemaType::Null]))],
        'TeamDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/Team/properties/x',
        '`x` states only `null`, so it is `mixed`.',
    ],
    'a list whose items state no type' => [
        static fn (): array => [teamHolding(new Schema(types: [SchemaType::Array], items: new Schema))],
        'TeamDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/Team/properties/x/items',
        '`xItem` states no type, so it is `mixed`.',
    ],
    'a recursion with no class to point at' => [
        static fn (): array => [answeringWithSchema(new Schema(types: [SchemaType::Object], properties: [
            'link' => Schema::recursion('#/paths/~1showThing/get/responses/200/content/application~1json/schema'),
        ]))],
        'ShowThing200ResponseDto',
        UntypedResponseCause::UnbuiltRecursion,
        '#/paths/~1showThing/get/responses/200/content/application~1json/schema/properties/link',
        '`link` points back at `#/paths/~1showThing/get/responses/200/content/application~1json/schema`, which is not a single named schema',
    ],
    'properties written beside the reference' => [
        static fn (): array => [answeringWithSchema(responseComponent('Node', [
            'label' => responseString(),
            'link' => new Schema(
                name: 'Node',
                types: [SchemaType::Object],
                properties: ['extra' => responseString()],
                recursesTo: '#/components/schemas/Node',
            ),
        ]))],
        'NodeDto',
        UntypedResponseCause::PropertiesBesideReference,
        '#/components/schemas/Node/properties/link',
        '`link` writes properties beside the reference, which the class of `Node` does not carry',
    ],
    'a target that is not an object with properties' => [
        static fn (): array => [answeringWithSchema(responseComponent('Shelf', ['link' => new Schema(
            name: 'Forest',
            source: '#/components/schemas/Forest',
            types: [SchemaType::Array],
            items: new Schema(types: [SchemaType::Object], properties: [
                'link' => Schema::recursion('#/components/schemas/Forest', 'Forest'),
            ]),
        )]))],
        'ShelfLinkItemDto',
        UntypedResponseCause::AncestorNotAnObject,
        '#/components/schemas/Shelf/properties/link/items/properties/link',
        '`link` points back at `#/components/schemas/Forest`, which is not an object with properties',
    ],
    'an expanded ancestor that states no single type' => [
        static fn (): array => [answeringWithSchema(new Schema(
            name: 'Node',
            source: '#/components/schemas/Node',
            properties: ['link' => Schema::recursion('#/components/schemas/Node', 'Node')],
        ))],
        'NodeDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/Node/properties/link',
        '`link` states no type, so it is `mixed`.',
    ],
    'values beside declared properties' => [
        static fn (): array => extractFixture('response-maps.yaml'),
        'PetDto',
        UntypedResponseCause::ValuesBesideProperties,
        '#/components/schemas/Pet/additionalProperties',
        '`additionalProperties` types the values of the keys that are not declared, but this type carries only the declared properties',
    ],
]);

it('records no cause for a value a response DTO types or leaves out on purpose', function (Closure $operations): void {
    expect((new ResponseDtoPlanner)->plan($operations(), [])->untyped)->toBe([]);
})->with([
    'a writeOnly property' => [static fn (): array => [teamHolding(new Schema(types: [SchemaType::String], writeOnly: true))]],
    'a key that is renamed' => [static fn (): array => [answeringWithSchema(responseComponent('Team', ['user-id' => responseString()]))]],
    'a map with no properties' => [static fn (): array => extractFixture('response-dto-golden.yaml')],
    'additionalProperties: true beside properties' => [static fn (): array => [answeringWithSchema(new Schema(
        name: 'Team',
        source: '#/components/schemas/Team',
        types: [SchemaType::Object],
        properties: ['name' => responseString()],
        additionalProperties: true,
    ))]],
    'a list with no items' => [static fn (): array => [teamHolding(new Schema(types: [SchemaType::Array]))]],
    'a property a schema at fault turned mixed' => [static fn (): array => extractFixture('response-property-gives-way.yaml')],
]);

// What a value writes itself is what the doctor already names when it reads the keyword, so the record says which
// keywords those are, where they are written, and nothing of the schemas below.
it('records the keywords a mixed response value writes itself', function (): void {
    $plan = (new ResponseDtoPlanner)->plan(extractFixture('response-untyped-keywords.yaml'), []);
    $byOwner = [];

    foreach ($plan->untyped as $value) {
        $byOwner[$value->owner][$value->position] = $value->keywords;
    }

    expect($byOwner['OrderDto'])->toBe([
        '#/components/schemas/Order/properties/payment' => ['oneOf' => '#/components/schemas/Order/properties/payment/oneOf'],
        '#/components/schemas/Order/properties/refund' => ['oneOf' => '#/components/schemas/Refund/oneOf'],
        '#/components/schemas/Order/properties/note' => ['anyOf' => '#/components/schemas/Note/anyOf'],
    ])
        ->and($byOwner['OrderCustomerDto'])->toBe([
            '#/components/schemas/Order/properties/customer/properties/contact' => [
                'anyOf' => '#/components/schemas/Order/properties/customer/properties/contact/anyOf',
            ],
        ]);
});

// The survey walk meets every schema, including one that then gives way and is never built, under a key of its own
// that no class carries: what it meets there is not what the build writes.
it('records nothing untyped from the survey walk', function (): void {
    $plan = (new ResponseDtoPlanner)->plan(extractFixture('response-inline-gives-way-with-union.yaml'), []);

    expect(untypedOf($plan))->toBe([[
        'UserAddressDto',
        UntypedResponseCause::NoSingleType,
        '#/components/schemas/UserAddress/properties/note',
    ]])
        ->and(array_column($plan->dtos, 'shortName'))->toBe(['UserDto', 'UserAddressDto']);
});
