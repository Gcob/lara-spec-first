<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gcob\LaraSpecFirst\Data\Optional;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\AddressDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\EventDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\GlossaryDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\LabelDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\RoleDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\SettingsDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\TeamDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\UserDto;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

/*
 * A generated response DTO, run.
 *
 * The emitter tests assert what the class says, and Larastan judges the golden file's types. This is the third half:
 * what the class does with what an application hands it, which is where a cast that reads right and converts wrongly
 * shows up. The classes are the committed golden files, so they are the artefact rather than a copy of it.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */

/**
 * A `User` as an application holds it, in the order `toArray()` writes it: the required keys, then the optional ones in
 * the order the contract states them.
 *
 * @return array<string, mixed>
 */
function userArray(): array
{
    return [
        'id' => 1,
        'email' => 'ada@example.test',
        'nickname' => null,
        'address' => ['street' => '1 Main St', 'city' => null],
        'roles' => [['name' => 'admin'], ['name' => 'editor']],
        'tags' => ['a', 'b'],
        'user-id' => 'u-1',
    ];
}

it('builds a response DTO from an array and writes the contract keys back', function (): void {
    $dto = UserDto::from(userArray());
    $roles = $dto->roles instanceof Optional ? [] : $dto->roles;

    expect($dto->id)->toBe(1)
        ->and($dto->nickname)->toBeNull()
        ->and($dto->user_id)->toBe('u-1')
        // A nested `$ref` object is a nested DTO, and a list of them is built through the item's `from()`.
        ->and($dto->address)->toBeInstanceOf(AddressDto::class)
        ->and($roles[1])->toBeInstanceOf(RoleDto::class)
        ->and($roles[1]->name)->toBe('editor')
        ->and($dto->toArray())->toBe(userArray());
});

// A response is built from what the application holds, which nothing validated: a wrong type is not repaired.
it('refuses a value of the wrong type instead of casting it', function (): void {
    expect(fn () => UserDto::from([...userArray(), 'id' => '1']))
        ->toThrow(TypeError::class, '($id) must be of type int, string given');
});

it('builds a response DTO with every optional property absent', function (): void {
    $dto = new UserDto(id: 1, email: 'ada@example.test');

    expect($dto->nickname)->toBeInstanceOf(Optional::class)
        ->and($dto->roles)->toBeInstanceOf(Optional::class)
        ->and($dto->toArray())->toBe(['id' => 1, 'email' => 'ada@example.test'])
        ->and(UserDto::from(['id' => 1, 'email' => 'ada@example.test'])->toArray())->toBe(['id' => 1, 'email' => 'ada@example.test']);
});

// Laravel's own JSON encoding of a date: UTC, a `Z`, and microseconds.
it('writes a response date-time back', function (string $key, ?string $given, ?string $expected): void {
    $dto = EventDto::from([
        'name' => 'Launch',
        'starts_at' => '2026-11-14T10:00:00Z',
        'closes_at' => null,
        'day' => '2026-11-14',
        $key => $given,
    ]);
    $written = $dto->toArray();

    expect($given === null ? $dto->{$key} : $dto->{$key} instanceof CarbonImmutable)->toBe($given === null ? null : true)
        ->and($written[$key])->toBe($expected)
        ->and(json_decode((string) json_encode($dto), true)[$key])->toBe($expected);
})->with([
    'with a fraction' => ['starts_at', '2026-11-14T10:00:00.123456Z', '2026-11-14T10:00:00.123456Z'],
    'with an offset' => ['starts_at', '2026-11-14T10:00:00.123456-05:00', '2026-11-14T15:00:00.123456Z'],
    'without a fraction' => ['starts_at', '2026-11-14T10:00:00Z', '2026-11-14T10:00:00.000000Z'],
    'a date' => ['day', '2026-11-14', '2026-11-14'],
    'null' => ['closes_at', null, null],
]);

it('writes the date-times of a list back the same way', function (): void {
    $dto = UserDto::from([...userArray(), 'visits' => ['2026-11-14T10:00:00.123456-05:00']]);

    expect($dto->toArray()['visits'])->toBe(['2026-11-14T15:00:00.123456Z']);
});

// The encoder is given the nested DTOs themselves, so each one writes its own object.
it('hands a response DTO to the encoder as a JSON object', function (): void {
    expect(json_encode(UserDto::from(['id' => 1, 'email' => 'a', 'address' => ['street' => 's'], 'roles' => [['name' => 'r']]])))
        ->toBe('{"id":1,"email":"a","address":{"street":"s"},"roles":[{"name":"r"}]}');
});

// Laravel calls `toArray()` on an `Arrayable` before it encodes, which would write an empty map as `[]`. A response DTO
// is `JsonSerializable` only, so each of these paths reaches `jsonSerialize()` and writes the object the contract says.
it('writes a response DTO as a JSON object on every path Laravel sends it down', function (Closure $send): void {
    expect($send(GlossaryDto::from([]), SettingsDto::from([])))->toBe('{}');
})->with([
    'a response with a status' => [static fn (GlossaryDto $glossary): string => (string) (new Response($glossary, 201))->getContent()],
    'an object with every property absent' => [static fn (GlossaryDto $glossary, SettingsDto $settings): string => (string) (new Response($settings, 201))->getContent()],
    'a collection as JSON' => [static fn (GlossaryDto $glossary): string => substr(collect([$glossary])->toJson(), 1, -1)],
    'a collection as an array' => [static fn (GlossaryDto $glossary): string => json_encode(collect([$glossary])->toArray()[0], JSON_THROW_ON_ERROR)],
    'a paginator' => [static fn (GlossaryDto $glossary): string => json_encode((new LengthAwarePaginator([$glossary], 1, 15))->toArray()['data'][0], JSON_THROW_ON_ERROR)],
]);

/**
 * A response object or map as the root of a response and as a property of a team: how to build each from a value, the
 * property of the team that holds it, and the JSON the value is written as.
 *
 * A value is what an application holds, so `[0 => 'a', 1 => 'b']` is its `['a', 'b']`, and a key written `"0"` is an
 * `int` before the DTO sees it.
 *
 * @return array<string, array{0: (Closure(array<array-key, mixed>): JsonSerializable)|null, 1: string, 2: array<array-key, mixed>, 3: string}>
 */
function responseShapes(): array
{
    return [
        'an object whose optional properties are all absent' => [static fn (array $value): SettingsDto => SettingsDto::from($value), 'settings', [], '{}'],
        'an empty map' => [static fn (array $value): GlossaryDto => GlossaryDto::from($value), 'notes', [], '{}'],
        'a map keyed 0 and 1' => [static fn (array $value): GlossaryDto => GlossaryDto::from($value), 'notes', [0 => 'a', 1 => 'b'], '{"0":"a","1":"b"}'],
        'an empty map of objects' => [null, 'labels', [], '{}'],
    ];
}

// `[]` is a JSON array, and an object with nothing to say or a map keyed `0` and `1` is a JSON object: the encoder is
// told which by `(object)`, at the root of a response and one level down.
it('writes every response object and map as a JSON object', function (?Closure $root, string $key, array $value, string $json): void {
    if ($root !== null) {
        expect(json_encode($root($value)))->toBe($json);
    }

    expect(json_encode(TeamDto::from(['name' => 'Core', $key => $value])))->toBe('{"name":"Core","'.$key.'":'.$json.'}');
})->with(responseShapes());

it('writes a map of response objects as a JSON object keyed as it was', function (): void {
    $team = TeamDto::from(['name' => 'Core', 'labels' => ['0' => ['text' => 'x'], 'urgent' => ['text' => 'y']]]);

    expect($team->labels)->toEqual([0 => new LabelDto('x'), 'urgent' => new LabelDto('y')])
        ->and(json_encode($team))->toBe('{"name":"Core","labels":{"0":{"text":"x"},"urgent":{"text":"y"}}}');
});

// The root map is the map itself: its JSON is not `{"additionalProperties": ...}`.
it('writes the keys of a root map and nothing around them', function (): void {
    expect(json_encode(GlossaryDto::from(['dto' => 'a typed bag of data'])))->toBe('{"dto":"a typed bag of data"}')
        ->and(GlossaryDto::from(['dto' => 'a typed bag of data'])->additionalProperties)->toBe(['dto' => 'a typed bag of data']);
});

// `toArray()` is for PHP, so a map stays a PHP array at every depth, and an empty one is `[]`.
it('keeps toArray a PHP array for a response object or map', function (?Closure $root, string $key, array $value): void {
    if ($root !== null) {
        expect($root($value)->toArray())->toBe($value);
    }

    expect(TeamDto::from(['name' => 'Core', $key => $value])->toArray())->toBe(['name' => 'Core', $key => $value]);
})->with(array_map(
    static fn (array $row): array => [$row[0], $row[1], $row[2]],
    responseShapes(),
));

// A map is its keys: from() keeps them, whatever they are.
it('holds the keys of a response map whole', function (): void {
    expect(GlossaryDto::from(['a' => 'x', 'b' => 'y'])->additionalProperties)->toBe(['a' => 'x', 'b' => 'y'])
        ->and(GlossaryDto::from([])->additionalProperties)->toBe([]);
});
