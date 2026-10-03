<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gcob\LaraSpecFirst\Data\Optional;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\AddressInputDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\NewUserInputDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\NewUserPartialInputDto;
use Illuminate\Http\UploadedFile;

/*
 * A generated DTO, run.
 *
 * The emitter tests assert what the class says, and Larastan judges the golden
 * file's types. This is the third half: what the class does when a validated
 * payload goes through it, which is where a cast that reads right and converts
 * wrongly shows up. The classes are the committed golden files, so they are the
 * artefact rather than a copy of it.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */

/**
 * What `validated()` hands a multipart body: every scalar a string.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function multipartPayload(array $overrides = []): array
{
    return [
        'email' => 'ada@example.test',
        'born_on' => null,
        'nickname' => null,
        'address' => ['street' => '1 Main St'],
        ...$overrides,
    ];
}

it('casts what a multipart body delivers as strings', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'age' => '42',
        'score' => '1.5',
        'active' => '0',
        'level' => '2',
        'ids' => ['1', '2'],
        '2fa' => '1',
    ]));

    expect($dto->age)->toBe(42)
        ->and($dto->score)->toBe(1.5)
        ->and($dto->active)->toBeFalse()
        ->and($dto->level)->toBe(2)
        ->and($dto->ids)->toBe([1, 2])
        ->and($dto->_2fa)->toBeTrue();
});

// `(int) $payload` over a list the validator already proved is one.
it('builds a nested list of lists with its own casts', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['matrix' => [['1', '2'], ['3']]]));

    expect($dto->matrix)->toBe([[1, 2], [3]]);
});

// `CarbonImmutable::parse(null)` is the current time: a nullable date has to be
// tested before anything converts it.
it('keeps a null date null instead of turning it into now', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['born_on' => null]));

    expect($dto->born_on)->toBeNull();
});

it('parses the dates the rule set accepted', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'born_on' => '1990-01-02',
        'seen_at' => '2026-10-03T13:52:51.123456789Z',
        'visits' => ['2026-10-03T13:52:51Z'],
    ]));

    expect($dto->born_on)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dto->born_on?->toDateString())->toBe('1990-01-02')
        ->and($dto->seen_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dto->visits)->toHaveCount(1);
});

it('builds nested DTOs, a recursive one included', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'address' => ['street' => '1 Main St', 'parent' => ['street' => '2 Side St']],
        'profile' => ['bio' => 'Hello'],
        'roles' => [['name' => 'admin'], ['name' => 'member']],
    ]));

    expect($dto->address)->toBeInstanceOf(AddressInputDto::class)
        ->and($dto->address->parent)->toBeInstanceOf(AddressInputDto::class)
        ->and($dto->address->parent instanceof AddressInputDto ? $dto->address->parent->street : null)->toBe('2 Side St')
        ->and($dto->roles)->toHaveCount(2);
});

it('hands an upload through untouched', function (): void {
    $file = UploadedFile::fake()->create('a.png', 1, 'image/png');

    expect(NewUserInputDto::from(multipartPayload(['avatar' => $file]))->avatar)->toBe($file);
});

// The reason the type exists, asserted the way `request-validation.md` states it:
// a field the client did not send must not be written back as `null`.
it('does not write a null over a field the client did not send', function (): void {
    $dto = NewUserPartialInputDto::from(['email' => 'ada@example.test']);

    expect($dto->nickname)->toBeInstanceOf(Optional::class)
        ->and($dto->toArray())->toBe(['email' => 'ada@example.test']);
});

// ...and a `null` the client did send on purpose is written.
it('writes a null the client sent on purpose', function (): void {
    $dto = NewUserPartialInputDto::from(['nickname' => null]);

    expect($dto->nickname)->toBeNull()
        ->and($dto->toArray())->toBe(['nickname' => null]);
});

it('writes the contract\'s keys back out, the way the contract spells them', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'born_on' => '1990-01-02',
        'seen_at' => '2026-10-03T13:52:51Z',
        'roles' => [['name' => 'admin']],
        'visits' => ['2026-10-03T13:52:51Z'],
        'user-id' => 'u1',
        '2fa' => '1',
        'ids' => ['1'],
    ]));

    expect($dto->toArray())->toBe([
        'email' => 'ada@example.test',
        'born_on' => '1990-01-02',
        'nickname' => null,
        'address' => ['street' => '1 Main St'],
        'seen_at' => '2026-10-03T13:52:51+00:00',
        'ids' => [1],
        'roles' => [['name' => 'admin']],
        'visits' => ['2026-10-03T13:52:51+00:00'],
        'user-id' => 'u1',
        '2fa' => true,
    ]);
});

// A DTO returned from a controller becomes a JSON response with nothing
// registered, which is what `JsonSerializable` is there for.
it('serializes to the same array', function (): void {
    $dto = NewUserInputDto::from(multipartPayload());

    expect(json_decode((string) json_encode($dto), true))->toBe($dto->toArray());
});

// `Illuminate\Support\Optional` is the class behind `optional()`, and the one an
// IDE auto-imports. The golden files import this package's, explicitly.
it('imports the package\'s Optional and not Laravel\'s', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Generated/Data/NewUserInputDto.php');

    expect($source)->toContain('use Gcob\\LaraSpecFirst\\Data\\Optional;')
        ->and($source)->not->toContain('Illuminate\\Support\\Optional');
});
