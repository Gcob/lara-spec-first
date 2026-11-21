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
    return array_column((new ResponseDtoPlanner)->plan(extractFixture('response-dto-golden.yaml'), [])->dtos, null, 'shortName');
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
        ->emit((new ResponseDtoPlanner)->plan([$operation], [])->dtos[0])->contents;
}

it('emits a final readonly response DTO for a referenced object schema', function (): void {
    $emitted = emittedResponseDto('UserDto');

    // Not `UserInputDto`: the response side carries no `Input`.
    expect($emitted)
        ->toContain('namespace App\\Http\\Generated\\Data;')
        ->toContain('final readonly class UserDto implements JsonSerializable')
        ->toContain('public static function from(array $payload): self')
        ->toContain('public function toArray(): array')
        ->toContain('public function jsonSerialize(): object');
    // Not `Arrayable`: Laravel calls `toArray()` on one before it encodes, which writes an empty map as `[]`.
    expect($emitted)->not->toContain('InputDto');
    expect($emitted)->not->toContain('Arrayable');

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

// Why: only a request sets a `date-time` to the app timezone. A response is built from what the application holds and
// is written as Laravel's JSON encoding of a date is, so its `from()` keeps the timezone a value arrived in (card #241,
// ADR 0025).
it('leaves a response date-time in the timezone it arrived in', function (): void {
    expect(emittedResponseDto('EventDto'))
        ->toContain("starts_at: CarbonImmutable::parse(\$payload['starts_at']),")
        ->toContain("\$payload['closes_at'] === null ? null : CarbonImmutable::parse(\$payload['closes_at'])");
    expect(emittedResponseDto('EventDto'))->not->toContain('date_default_timezone_get');
});

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

// A response property with no single type is what the schema says and no more: `mixed`, and the file says why. Its
// findings do not send the reader to a request's, since a response has none.
it('reads a response property with no single type as mixed and says so', function (Schema $schema, string $reason, bool $optional): void {
    $emitted = emittedUserResponse(['value' => $schema], $optional ? [] : ['value']);

    expect($emitted)
        ->toContain('public mixed $value'.($optional ? ' = new Optional' : '').',')
        ->toContain('`value` states '.$reason.', so it is `mixed`.');
    expect($emitted)->not->toContain('request');

    // An optional `mixed` cannot be in a union, so the docblock says `Optional|mixed` for it.
    if ($optional) {
        expect($emitted)->toContain('@param  Optional|mixed  $value');
    }
})->with([
    'a union' => [new Schema(types: [SchemaType::String, SchemaType::Integer]), 'more than one type', false],
    'no type, as oneOf leaves it' => [new Schema, 'no type', false],
    'an optional union' => [new Schema(types: [SchemaType::String, SchemaType::Integer]), 'more than one type', true],
]);

/**
 * Every response DTO file a contract produces, by class name.
 *
 * @return array<string, string>
 */
function responseFilesOf(string $fixture): array
{
    $plan = (new ResponseDtoPlanner)->plan(extractFixture($fixture), []);
    $emitter = new ResponseDtoEmitter('App\\Http\\Generated', 'openapi.yaml');

    return array_combine(
        array_map(static fn (PlannedResponseDto $dto): string => $dto->shortName, $plan->dtos),
        array_map(static fn (PlannedResponseDto $dto): string => $emitter->emit($dto)->contents, $plan->dtos),
    );
}

// The oracle: a `$ref` with keywords beside it at 3.1 means what the `allOf` spelling of it means, so the two give the
// same DTO files byte for byte. At 3.0 the reference replaces what is beside it, and the schema is `User` alone.
it('types a response ref with siblings as its allOf spelling', function (): void {
    $beside = responseFilesOf('response-ref-siblings.yaml');

    expect($beside)->toBe(responseFilesOf('response-ref-allof.yaml'))
        ->and(array_keys($beside))->toBe(['ShowUser200ResponseDto'])
        ->and($beside['ShowUser200ResponseDto'])->toContain("public int \$id,\n        public string \$email,\n");

    $ignored = responseFilesOf('response-ref-siblings-30.yaml');

    expect(array_keys($ignored))->toBe(['UserDto'])
        ->and($ignored['UserDto'])->toContain('public Optional|string $email = new Optional,');
});
