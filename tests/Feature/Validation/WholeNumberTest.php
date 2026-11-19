<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\WholeNumber;
use Illuminate\Support\Facades\Validator;

/*
 * The rule a generated request puts on an integer of a body that is JSON and nothing else: an integer, or a float whose
 * value is exactly whole. Run through a real validator, with the float itself, since the reason it exists is that
 * Laravel's own `integer` reads a float as text with 14 significant digits.
 *
 * @see docs/guide/code-generation/request-validation.md — "A JSON body keeps its types"
 */

/**
 * Whether `$value` passes `field` => WholeNumber, or `WholeNumber(acceptsText: true)`.
 */
function passesWholeNumber(mixed $value, bool $acceptsText = false): bool
{
    $rule = $acceptsText ? new WholeNumber(acceptsText: true) : new WholeNumber;

    return Validator::make(['field' => $value], ['field' => [$rule]])->passes();
}

it('accepts an integer, and a float whose value is exactly whole', function (mixed $value): void {
    expect(passesWholeNumber($value))->toBeTrue();
})->with([
    'an integer' => [42],
    'zero' => [0],
    'a negative integer' => [-7],
    'the largest integer' => [PHP_INT_MAX],
    'a whole float' => [1.0],
    'an exponent' => [1e2],
    'negative zero' => [-0.0],
    'one hundred trillion' => [1e14],
    'past 2^53' => [9007199254740992.0],
    'the smallest integer as a float' => [-9223372036854775808.0],
]);

// The near-whole floats are the point: `integer` reads each of them as a whole number, and the cast then truncates.
it('refuses a float that is not exactly whole', function (float $value): void {
    expect(passesWholeNumber($value))->toBeFalse();
})->with([
    'a fraction' => [1.5],
    'just under three' => [2.9999999999999996],
    'just under five' => [4.999999999999999],
    'just over five' => [5.00000000000001],
    'a large float with a fraction' => [12345678901234.6],
    'not a number' => [NAN],
]);

// 2^63 is the first float an `(int)` cannot hold: it warns and wraps.
it('refuses a float outside the range of a 64-bit integer', function (float $value): void {
    expect(passesWholeNumber($value))->toBeFalse();
})->with([
    'past the largest integer' => [9223372036854775808.0],
    'a hundred quintillion' => [1e20],
    'past the smallest integer' => [-1e20],
    'infinity' => [INF],
    'negative infinity' => [-INF],
]);

it('refuses what is no number, a numeric string and a boolean included', function (mixed $value): void {
    expect(passesWholeNumber($value))->toBeFalse();
})->with([
    'a numeric string' => ['42'],
    'a string that spells a float' => ['1.0'],
    'text' => ['abc'],
    'true' => [true],
    'false' => [false],
    'null' => [null],
    'a list' => [[1]],
]);

it('answers with the language line Laravel\'s integer does', function (): void {
    $validator = Validator::make(['field' => 1.5], ['field' => [new WholeNumber]]);

    expect($validator->errors()->first('field'))->toBe('The field field must be an integer.');
});

// A plain rule, as Laravel's `integer` is: not called for an absent key, and `nullable` lets `null` through.
it('is skipped for an absent key and for null on a nullable field', function (): void {
    expect(Validator::make([], ['field' => ['sometimes', new WholeNumber]])->passes())->toBeTrue()
        ->and(Validator::make(['field' => null], ['field' => ['nullable', new WholeNumber]])->passes())->toBeTrue();
});

// `min` and `max` find the number they compare by the name of a rule beside them, and an object has none: `numeric`
// is that name, and the rule keeps judging the value first.
it('compares the bounds as numbers beside a rule named numeric', function (mixed $value, bool $passes): void {
    $rules = ['field' => [new WholeNumber, 'numeric', 'min:5', 'max:100']];

    expect(Validator::make(['field' => $value], $rules)->passes())->toBe($passes);
})->with([
    'a whole float inside' => [10.0, true],
    'an exponent at the maximum' => [1e2, true],
    'a whole float under the minimum' => [3.0, false],
    'a whole float over the maximum' => [1.01e2, false],
    'a fraction inside' => [10.5, false],
]);

// The rule of a body that is JSON and a form: a form carries every value as text, and the value's PHP type tells that
// text from a JSON number. The text passes when Laravel's own `integer` passes it, so a form client is judged as before.
it('accepts the text of an integer when it accepts text, as Laravel\'s integer does', function (mixed $value): void {
    expect(passesWholeNumber($value, acceptsText: true))->toBeTrue();
})->with([
    'text' => ['42'],
    'a plus sign' => ['+42'],
    'negative zero' => ['-0'],
    'zero' => ['0'],
    'a leading space' => [' 42'],
    'the largest integer' => ['9223372036854775807'],
    'an integer' => [42],
    'a whole float' => [3.0],
    'one hundred trillion' => [1e14],
]);

it('still refuses what is not a whole number when it accepts text', function (mixed $value): void {
    expect(passesWholeNumber($value, acceptsText: true))->toBeFalse();
})->with([
    'a leading zero' => ['042'],
    'text that spells a float' => ['1.0'],
    'an exponent as text' => ['1e2'],
    'text' => ['abc'],
    'past the largest integer as text' => ['9223372036854775808'],
    'a near-whole float as text' => ['2.9999999999999996'],
    'a near-whole float' => [2.9999999999999996],
    'a fraction' => [1.5],
    'past 2^63' => [1e20],
    'true' => [true],
    'false' => [false],
    'a list' => [[]],
]);

it('answers with the language line of integer when it accepts text', function (): void {
    $validator = Validator::make(['field' => 'abc'], ['field' => [new WholeNumber(acceptsText: true)]]);

    expect($validator->errors()->first('field'))->toBe('The field field must be an integer.');
});

// A control: the default is a JSON body's rule, which has no text to read.
it('refuses the text of an integer by default', function (): void {
    expect((new WholeNumber)->acceptsText)->toBeFalse()
        ->and(passesWholeNumber('42'))->toBeFalse();
});
