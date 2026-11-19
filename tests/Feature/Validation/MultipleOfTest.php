<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\MultipleOf;
use Gcob\LaraSpecFirst\Validation\UndeclaredKeys;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

/*
 * The rule a generated request puts on a field with `multipleOf`. Laravel's own `multiple_of` throws on a number PHP
 * cannot hold (a JSON `1e400` is read as `INF`, and a text exponent past 64 bits cannot be read at all), so this one
 * refuses it, and answers every other value as `multiple_of` does. Run through a real validator, with Laravel's own
 * rule as the reference, since "answers as `multiple_of` does" is the whole claim.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */

/**
 * The validator for `$value` on `field`, with `$typeRules` beside the rule under test.
 *
 * @param  array<mixed>  $typeRules
 */
function validatorForMultipleOf(mixed $value, array $typeRules, string|MultipleOf $rule): LaravelValidator
{
    return Validator::make(['field' => $value], ['field' => [...$typeRules, $rule]]);
}

/**
 * The type rule's own message for `$value`, which the rule beside it does not change: `numeric` takes `INF` and a text
 * that reads as a number, `numeric:strict` refuses the text, and `integer` refuses both.
 *
 * @return list<string>
 */
function typeMessageOf(string $typeRule, mixed $value): array
{
    return match (true) {
        $typeRule === 'numeric:strict' && is_string($value) => ['The field field must be a number.'],
        $typeRule === 'integer' => ['The field field must be an integer.'],
        default => [],
    };
}

/**
 * Every pair of a value and the type rules it is judged beside, for each divisor.
 *
 * @param  array<string, mixed>  $values  by label
 * @param  list<string>  $divisors
 * @param  list<list<string>>  $typeRulesTried
 */
function multipleOfRows(array $values, array $divisors, array $typeRulesTried = [[], ['numeric'], ['numeric:strict'], ['integer']]): Generator
{
    foreach ($values as $label => $value) {
        foreach ($divisors as $divisor) {
            foreach ($typeRulesTried as $typeRules) {
                yield $label.' by '.$divisor.' beside '.($typeRules === [] ? 'nothing' : $typeRules[0]) => [$value, $divisor, $typeRules];
            }
        }
    }
}

// A number PHP cannot hold has lost its value once read, so it is refused whatever the divisor. Laravel's `multiple_of`
// throws a `MathException` on each of these, and a `NAN` or an `INF` would also reach PHP's string casts. The type
// rule beside it keeps its own message. `NAN` is not tried beside `integer`: Laravel's own `integer` turns PHP 8.5's
// `filter_var(NAN)` warning into an exception whatever this rule does.
it('refuses a number PHP cannot hold, with the message of multiple_of', function (mixed $value, string $divisor, array $typeRules): void {
    $validator = validatorForMultipleOf($value, $typeRules, new MultipleOf($divisor));
    $type = $typeRules === [] ? [] : typeMessageOf($typeRules[0], $value);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->get('field'))->toBe([...$type, "The field field must be a multiple of {$divisor}."])
        ->and($validator->failed()['field']['MultipleOf'])->toBe([$divisor]);
})->with(function (): Generator {
    yield from multipleOfRows([
        'INF' => INF,
        '-INF' => -INF,
        'a text exponent past 64 bits' => '1e99999999999999999999',
        'a negative text exponent past 64 bits' => '1e-99999999999999999999',
    ], ['0.5', '2']);

    yield from multipleOfRows(['NAN' => NAN], ['0.5', '2'], [[], ['numeric'], ['numeric:strict']]);
});

// The heart of the card: for every value Laravel answered, the errors and the failed rule are the ones `multiple_of`
// gives, so a form or query client's text, a message a project customized and a `failed()` reader see no change.
it('answers every other value as multiple_of does', function (mixed $value, string $divisor, array $typeRules): void {
    $reference = validatorForMultipleOf($value, $typeRules, 'multiple_of:'.$divisor);
    $ours = validatorForMultipleOf($value, $typeRules, new MultipleOf($divisor));

    expect($ours->errors()->toArray())->toBe($reference->errors()->toArray())
        ->and($ours->failed())->toBe($reference->failed());
})->with(function (): Generator {
    yield from multipleOfRows([
        'a multiple' => 4,
        'not a multiple' => 3,
        'a text multiple' => '4',
        'a text that is not' => '3',
        'a negative multiple' => -6,
        'zero' => 0,
        'a float that is not' => 1.2,
        'a float below the range of INF' => 1e308,
        'a text past the range of INF' => '1e400',
        'text' => 'abc',
        'true' => true,
        'a list' => [],
        'an empty string' => '',
        'null' => null,
    ], ['2']);

    yield from multipleOfRows(['a quarter multiple' => 0.75, 'not a quarter multiple' => 0.8], ['0.25']);
    yield from multipleOfRows(['a half multiple' => 1.5, 'not a half multiple' => 1.2], ['0.5']);
    yield from multipleOfRows(['zero by zero' => 0, 'five by zero' => 5], ['0'], [[], ['numeric']]);
});

// A project customizes `multiple_of` through the lang file or `messages()`, and the rule reports under that name, so
// whatever it wrote applies here too. A `$fail(...)` would lose both: it reports under the class name. Each row is
// compared with Laravel's own rule as well, so the expected text is what `multiple_of` itself says.
it('applies what a project customizes for multiple_of', function (bool $langLine, array $messages, array $attributes, string $expected): void {
    $judge = function (string|MultipleOf $rule) use ($langLine, $messages, $attributes): string {
        if ($langLine) {
            app('translator')->addLines(['validation.custom.field.multiple_of' => 'Lang line for :attribute, divisor :value.'], 'en');
        }

        return Validator::make(['field' => 3], ['field' => ['numeric', $rule]], $messages, $attributes)->errors()->first('field');
    };

    expect($judge(new MultipleOf('2')))->toBe($expected)
        ->and($judge('multiple_of:2'))->toBe($expected);
})->with([
    'the lang line of the field' => [true, [], [], 'Lang line for field, divisor 2.'],
    'an attribute name beside the lang line' => [true, [], ['field' => 'amount'], 'Lang line for amount, divisor 2.'],
    'a messages() entry of the rule' => [false, ['multiple_of' => 'Global :attribute by :value.'], [], 'Global field by 2.'],
    'a messages() entry of the field' => [false, ['field.multiple_of' => 'Own :attribute by :value.'], [], 'Own field by 2.'],
    'a messages() entry and an attribute name' => [false, ['multiple_of' => 'Global :attribute by :value.'], ['field' => 'amount'], 'Global amount by 2.'],
]);

it('applies a lang line written for a wildcard to the elements it reaches', function (): void {
    app('translator')->addLines(['validation.custom.xs.*.multiple_of' => 'Element :attribute is not a multiple of :value.'], 'en');

    $validator = Validator::make(
        ['xs' => [4, 3, INF]],
        ['xs.*' => ['numeric', new MultipleOf('2')]],
    );

    expect($validator->errors()->toArray())->toBe([
        'xs.1' => ['Element xs.1 is not a multiple of 2.'],
        'xs.2' => ['Element xs.2 is not a multiple of 2.'],
    ]);
});

// A map's value rules sit under a wildcard inside `UndeclaredKeys`: the declared key keeps its own rules and the rest
// answer to the value rules, `INF` included.
it('refuses an infinite value of a map judged by UndeclaredKeys', function (): void {
    $validator = Validator::make(
        ['m' => ['a' => 3, 'b' => INF, 'c' => 4]],
        [
            'm' => ['array'],
            'm.*' => new UndeclaredKeys([1 => ['a']], ['numeric:strict', new MultipleOf('2')]),
        ],
    );

    expect(array_keys($validator->errors()->toArray()))->toBe(['m.b']);
});

it('keeps the divisor it was built with', function (): void {
    expect((new MultipleOf('0.25'))->divisor)->toBe('0.25');
});
