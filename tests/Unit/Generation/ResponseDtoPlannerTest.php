<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;

/**
 * An operation answering `200` with a named object schema.
 */
function answeringWith(string $operationId, string $schemaName, string $source): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/'.$operationId),
        operationId: $operationId,
        responses: [new Response('200', ['application/json' => new Schema(
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

it('gives two operations answering with one schema the one DTO', function (): void {
    $dtos = (new ResponseDtoPlanner)->plan([
        answeringWith('showUser', 'User', '#/components/schemas/User'),
        answeringWith('showMember', 'User', '#/components/schemas/User'),
    ])->dtos;

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->readers)->toBe([
            ['label' => 'get /showUser', 'status' => '200'],
            ['label' => 'get /showMember', 'status' => '200'],
        ]);
});
