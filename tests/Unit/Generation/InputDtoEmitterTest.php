<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
use Gcob\LaraSpecFirst\Generation\InputDtoPlanner;
use Gcob\LaraSpecFirst\Generation\PlannedInputDto;
use Gcob\LaraSpecFirst\Generation\PlannedRequest;
use Gcob\LaraSpecFirst\Generation\RequestName;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Symfony\Component\Process\Process;

/*
 * The text of a generated input DTO, asserted the way any other output is.
 *
 * Emitter-level, with the golden files beside it as the other half: they pin
 * every row of the type table at once, and they are real classes, so Pint and
 * Larastan judge them on every run. The `list<T>` and literal docblocks in them
 * are claims Larastan checks against the code under them, which is what keeps
 * those docblocks true.
 *
 * @see tests/Fixtures/Generated/Data
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */

/**
 * Every DTO the golden contract produces, keyed by class name.
 *
 * @return array<string, PlannedInputDto>
 */
function goldenDtos(): array
{
    $requests = array_map(static function ($operation): PlannedRequest {
        $name = RequestName::for($operation);
        assert($name !== null);

        return new PlannedRequest($operation, $name, RuleSetBuilder::for($operation), 'Controller');
    }, extractFixture('input-dto-golden.yaml'));

    $dtos = [];

    foreach ((new InputDtoPlanner)->plan($requests)->dtos as $dto) {
        $dtos[$dto->shortName] = $dto;
    }

    return $dtos;
}

function emittedDto(string $shortName, string $namespace = 'App\\Http\\Generated', string $specPath = 'openapi.yaml'): string
{
    return (new InputDtoEmitter($namespace, $specPath))->emit(goldenDtos()[$shortName])->contents;
}

it('emits a final readonly class that is Arrayable and JsonSerializable', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain('namespace App\\Http\\Generated\\Data;')
        ->toContain('final readonly class NewUserInputDto implements Arrayable, JsonSerializable')
        ->toContain('use Illuminate\\Contracts\\Support\\Arrayable;')
        ->toContain('public static function from(array $payload): self')
        ->toContain('public function toArray(): array')
        ->toContain('public function jsonSerialize(): array');
});

it('imports Optional from this package explicitly', function (): void {
    expect(emittedDto('NewUserInputDto'))->toContain('use Gcob\\LaraSpecFirst\\Data\\Optional;');
});

it('imports nothing it does not use', function (): void {
    $emitted = emittedDto('AddressInputDto');

    expect($emitted)->not->toContain('CarbonImmutable')
        ->and($emitted)->not->toContain('UploadedFile');
});

// The table of dto-anatomy.md, row by row, on the request side.
it('declares each schema type as the table says', function (string $declaration): void {
    expect(emittedDto('NewUserInputDto'))->toContain($declaration);
})->with([
    'string' => ['public string $email,'],
    'optional integer' => ['public Optional|int $age,'],
    'optional number' => ['public Optional|float $score,'],
    'optional boolean' => ['public Optional|bool $active,'],
    'required nullable date' => ['public ?CarbonImmutable $born_on,'],
    'optional date-time' => ['public Optional|CarbonImmutable $seen_at,'],
    'enum as its scalar' => ['public Optional|string $status,'],
    'integer enum' => ['public Optional|int $level,'],
    'required nullable string' => ['public ?string $nickname,'],
    'a $ref object' => ['public AddressInputDto $address,'],
    'an inline object' => ['public Optional|NewUserProfileInputDto $profile,'],
    'a list' => ['public Optional|array $tags,'],
    'an object with no properties' => ['public Optional|array $meta,'],
    'a file part' => ['public Optional|UploadedFile $avatar,'],
    'no type at all' => ['public mixed $anything,'],
]);

it('writes an enumeration as a literal union in the docblock', function (): void {
    expect(emittedDto('NewUserInputDto'))->toContain("@param  Optional|'active'|'banned'  \$status")
        // Not for an integer enumeration: it is cast, and a cast is an `int`.
        ->and(emittedDto('NewUserInputDto'))->not->toContain('1|2');
});

it('writes a list as list<T> in the docblock', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain('@param  Optional|list<string>  $tags')
        ->toContain('@param  Optional|list<int>  $ids')
        ->toContain('@param  Optional|list<NewUserRolesItemInputDto>  $roles')
        ->toContain('@param  Optional|list<list<int>>  $matrix')
        ->toContain('@param  Optional|list<CarbonImmutable>  $visits')
        ->toContain('@param  Optional|array<string, mixed>  $meta');
});

it('casts the scalars the request side delivers as strings', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain("age: array_key_exists('age', \$payload) ? (int) \$payload['age'] : new Optional,")
        ->toContain("score: array_key_exists('score', \$payload) ? (float) \$payload['score'] : new Optional,")
        ->toContain("active: array_key_exists('active', \$payload) ? (bool) \$payload['active'] : new Optional,");
});

// `CarbonImmutable::parse(null)` is the current time, so a nullable date is
// checked before anything converts it.
it('tests for null before converting a nullable value', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain("born_on: \$payload['born_on'] === null ? null : CarbonImmutable::parse(\$payload['born_on']),")
        ->toContain("nickname: \$payload['nickname'],");
});

it('converts a list by what its elements are', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain("array_values(array_map(intval(...), \$payload['ids']))")
        ->toContain("array_values(array_map(NewUserRolesItemInputDto::from(...), \$payload['roles']))")
        ->toContain("array_values(array_map(CarbonImmutable::parse(...), \$payload['visits']))")
        ->toContain('array_values(array_map(static fn (array $item): array => array_values(array_map(intval(...), $item)), $payload[\'matrix\']))')
        // Strings go in as they are, and so do files.
        ->toContain("tags: array_key_exists('tags', \$payload) ? \$payload['tags'] : new Optional,");
});

it('writes the contract\'s keys back out', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain("'born_on' => \$this->born_on?->toDateString(),")
        ->toContain("\$array['seen_at'] = \$this->seen_at->toRfc3339String();")
        ->toContain("'address' => \$this->address->toArray(),")
        ->toContain('array_map(static fn (NewUserRolesItemInputDto $item): array => $item->toArray(), $this->roles)')
        ->toContain('array_map(static fn (CarbonImmutable $item): string => $item->toRfc3339String(), $this->visits)');
});

// The reason the type exists: an absent property must not be written back as a
// `null` over a stored column.
it('leaves an Optional out of toArray()', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain('if (! $this->age instanceof Optional) {')
        ->toContain("\$array['age'] = \$this->age;");
});

// A key that is not an identifier gets a derived name, and the key as written
// stays in `from()` and `toArray()`.
it('derives a property name for a key that is not an identifier', function (): void {
    expect(emittedDto('NewUserInputDto'))
        ->toContain('public Optional|string $user_id,')
        ->toContain('public Optional|bool $_2fa,')
        ->toContain("user_id: array_key_exists('user-id', \$payload) ? \$payload['user-id'] : new Optional,")
        ->toContain("\$array['2fa'] = \$this->_2fa;")
        ->toContain('`user-id` is not a PHP identifier, so it is read into `$user_id`');
});

it('makes every property of the partial type optional', function (): void {
    expect(emittedDto('NewUserPartialInputDto'))
        ->toContain('public Optional|string $email,')
        ->toContain('public Optional|CarbonImmutable|null $born_on,')
        ->toContain('public Optional|AddressInputDto $address,')
        ->and(emittedDto('NewUserPartialInputDto'))
        // A nested object inside a `PATCH` stays complete.
        ->not->toContain('AddressPartialInputDto');
});

it('points a recursive node at its own class', function (): void {
    expect(emittedDto('AddressInputDto'))
        ->toContain('public Optional|AddressInputDto $parent,')
        ->toContain("parent: array_key_exists('parent', \$payload) ? AddressInputDto::from(\$payload['parent']) : new Optional,");
});

it('carries the provenance of the schema it describes', function (): void {
    expect(emittedDto('NewUserInputDto', specPath: 'contracts/api.yaml'))
        ->toContain('contracts/api.yaml')
        ->toContain('#/components/schemas/NewUser')
        ->toContain('Class name taken from the schema `NewUser`')
        ->toContain('Read by `post /users`.');
});

it('says where an inline schema is written and how it got its name', function (): void {
    expect(emittedDto('NewUserProfileInputDto'))
        ->toContain('#/components/schemas/NewUser/properties/profile')
        ->toContain('Class name derived from its parent\'s name and the property `profile`');
});

it('says so when no operation reads a type directly', function (): void {
    expect(emittedDto('AddressInputDto'))
        ->toContain('No operation reads this type directly: it is the type of a property of another DTO.');
});

// The partial type is generated for every body, so one that nothing reads says so
// rather than looking like dead code.
it('says so when no operation reads the partial type', function (): void {
    $dtos = goldenDtos();

    expect($dtos['NewUserPartialInputDto']->readers)->toBe(['patch /users/{id}'])
        ->and($dtos['NewUserInputDto']->readers)->toBe(['post /users']);
});

it('leaves no trailing whitespace in what it writes', function (): void {
    foreach (array_keys(goldenDtos()) as $name) {
        foreach (explode("\n", emittedDto($name)) as $line) {
            expect($line)->toBe(rtrim($line));
        }
    }
});

/**
 * The DTOs the golden files pin, which are every one the contract produces.
 */
dataset('golden dtos', [
    'NewUserInputDto',
    'NewUserPartialInputDto',
    'AddressInputDto',
    'NewUserProfileInputDto',
    'NewUserRolesItemInputDto',
]);

it('reproduces the golden file byte for byte', function (string $name): void {
    $emitted = emittedDto(
        $name,
        namespace: 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\Generated',
        specPath: 'tests/Fixtures/input-dto-golden.yaml',
    );

    expect($emitted)->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Generated/Data/'.$name.'.php'));
})->with('golden dtos');

it('has a golden file for every DTO the contract produces', function (): void {
    $files = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(dirname(__DIR__, 2).'/Fixtures/Generated/Data/*.php') ?: [],
    );
    sort($files);
    $produced = array_keys(goldenDtos());
    sort($produced);

    expect($files)->toBe($produced);
});

// The build and a consumer's formatter rewrite each other forever if the emitted
// bytes are not already formatted. Asserted against the real formatter, on the
// golden files the test above pins as the emitter's own output.
it('emits bytes Pint has nothing to change in', function (): void {
    $pint = new Process(
        [PHP_BINARY, 'vendor/bin/pint', '--test', dirname(__DIR__, 2).'/Fixtures/Generated/Data'],
        dirname(__DIR__, 3),
    );

    $pint->run();

    expect($pint->getExitCode())->toBe(0, $pint->getOutput().$pint->getErrorOutput());
});
