<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gcob\LaraSpecFirst\Data\Optional;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\AddressDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\EventDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\RoleDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\UserDto;

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
