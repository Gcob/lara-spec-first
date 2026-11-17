<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
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
        ->and(statusesOf('listUsers')[0]->list)->toBeTrue()
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
