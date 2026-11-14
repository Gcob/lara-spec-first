<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Illuminate\Support\Facades\Validator;

/*
 * The rule a generated request puts on a nullable recursive field below its depth: nothing there but `null`, or no
 * key at all. Run through a real validator, since what matters is when the framework calls it.
 *
 * @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"
 */

/**
 * Whether `$data` passes `field` => NullOrAbsent.
 *
 * @param  array<string, mixed>  $data
 */
function passesNullOrAbsent(array $data): bool
{
    return Validator::make($data, ['field' => [new NullOrAbsent]])->passes();
}

it('accepts the key being absent, and null', function (array $data): void {
    expect(passesNullOrAbsent($data))->toBeTrue();
})->with([
    'absent' => [[]],
    'null' => [['field' => null]],
]);

// Implicit, so it runs for an empty string too: a non-implicit rule is skipped for `""`, which would let it through
// when a validator is fed the value directly. Over HTTP Laravel has turned it into `null` before this runs.
it('refuses any other value', function (mixed $value): void {
    expect(passesNullOrAbsent(['field' => $value]))->toBeFalse();
})->with([
    'an empty string' => [''],
    'an empty list' => [[]],
    'a list' => [[1]],
    'a map' => [['a' => 1]],
    'zero' => [0],
    'false' => [false],
    'a string' => ['x'],
]);

it('answers with the language line a closed object does', function (): void {
    $validator = Validator::make(['field' => 'x'], ['field' => [new NullOrAbsent]]);

    expect($validator->errors()->first('field'))->toBe('The field field is prohibited.');
});
