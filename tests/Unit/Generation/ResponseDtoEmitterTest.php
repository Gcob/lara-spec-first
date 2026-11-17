<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\PlannedResponseDto;
use Gcob\LaraSpecFirst\Generation\ResponseDtoEmitter;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;

/*
 * The text of a generated response DTO, asserted the way the input DTO's is.
 *
 * Emitter-level, with the golden files beside it as the other half: they are real classes, so Pint and Larastan judge
 * them on every run, and the execution test runs them.
 *
 * @see tests/Fixtures/GeneratedResponse/Data
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */

/**
 * Every response DTO the golden contract produces, keyed by class name.
 *
 * @return array<string, PlannedResponseDto>
 */
function goldenResponseDtos(): array
{
    return array_column((new ResponseDtoPlanner)->plan(extractFixture('response-dto-golden.yaml'))->dtos, null, 'shortName');
}

function emittedResponseDto(string $shortName, string $namespace = 'App\\Http\\Generated', string $specPath = 'openapi.yaml'): string
{
    return (new ResponseDtoEmitter($namespace, $specPath))->emit(goldenResponseDtos()[$shortName])->contents;
}

/**
 * The response DTO of a `User` schema with these properties, answered by `get /users/{id}`.
 *
 * @param  array<string, Schema>  $properties
 * @param  array<array-key, string>  $required
 */
function emittedUserResponse(array $properties, array $required = []): string
{
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users/{id}'),
        operationId: 'showUser',
        responses: [new Response('200', ['application/json' => new Schema(
            name: 'User',
            source: '#/components/schemas/User',
            types: [SchemaType::Object],
            properties: $properties,
            required: array_values($required),
        )])],
    );

    return (new ResponseDtoEmitter('App\\Http\\Generated', 'openapi.yaml'))
        ->emit((new ResponseDtoPlanner)->plan([$operation])->dtos[0])->contents;
}

it('emits a final readonly response DTO for a referenced object schema', function (): void {
    $emitted = emittedResponseDto('UserDto');

    // Not `UserInputDto`: the response side carries no `Input`.
    expect($emitted)
        ->toContain('namespace App\\Http\\Generated\\Data;')
        ->toContain('final readonly class UserDto implements Arrayable, JsonSerializable')
        ->toContain('use Illuminate\\Contracts\\Support\\Arrayable;')
        ->toContain('public static function from(array $payload): self')
        ->toContain('public function toArray(): array')
        ->toContain('public function jsonSerialize(): object');
    expect($emitted)->not->toContain('InputDto');

    // One promoted property per property of the schema: the required ones first, then the optional ones, each group in
    // the order the contract writes them (the contract writes `nickname` before `id`).
    preg_match_all('/^        public [^$]+\$(\w+)/m', $emitted, $found);

    expect($found[1])->toBe([
        'id', 'email',
        'nickname', 'score', 'active', 'status', 'level', 'address', 'roles', 'tags', 'visits', 'user_id',
    ]);
});

// The contract's own answer: where it is written, what read it, and the key that is not an identifier.
it('says where a response DTO comes from and which operation answers with it', function (): void {
    expect(emittedResponseDto('UserDto', specPath: 'contracts/api.yaml'))
        ->toContain('contracts/api.yaml')
        ->toContain('#/components/schemas/User')
        ->toContain('Class name taken from the schema `User`')
        ->toContain('Read by `get /users/{id}` (`200`).')
        ->toContain('`user-id` is not a PHP identifier, so it is read into `$user_id`');
    expect(emittedResponseDto('UserDto'))->not->toContain('@see');
});

it('types an optional response property', function (string $declaration, array $properties, array $required): void {
    expect(emittedUserResponse($properties, $required))->toContain($declaration);
})->with([
    'required' => ['public string $email,', ['email' => new Schema(types: [SchemaType::String])], ['email']],
    'optional' => ['public Optional|string $email = new Optional,', ['email' => new Schema(types: [SchemaType::String])], []],
    'nullable' => ['public ?string $email,', ['email' => new Schema(types: [SchemaType::String, SchemaType::Null])], ['email']],
    'optional and nullable' => ['public Optional|string|null $email = new Optional,', ['email' => new Schema(types: [SchemaType::String, SchemaType::Null])], []],
]);

it('matches the response DTO golden files', function (): void {
    $directory = dirname(__DIR__, 2).'/Fixtures/GeneratedResponse/Data';
    $files = array_map(static fn (string $path): string => basename($path, '.php'), glob($directory.'/*.php') ?: []);
    sort($files);
    $produced = array_keys(goldenResponseDtos());
    sort($produced);

    // A golden file for every DTO the contract produces, and none for one it does not.
    expect($files)->toBe($produced);

    foreach ($produced as $name) {
        expect(emittedResponseDto($name, 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\GeneratedResponse', 'tests/Fixtures/response-dto-golden.yaml'))
            ->toBe(file_get_contents($directory.'/'.$name.'.php'));
    }
});
