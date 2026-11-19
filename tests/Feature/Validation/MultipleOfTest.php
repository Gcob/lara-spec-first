<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\MultipleOf;
use Gcob\LaraSpecFirst\Validation\UndeclaredKeys;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

/*
 * The rule a generated request puts on a field with `multipleOf`. It decides from the number's digits and exponent,
 * where Laravel's `multiple_of` writes out every digit an exponent stands for, and it refuses a number PHP cannot hold
 * (a JSON `1e400` is read as `INF`). Every other value gets `multiple_of`'s answer, so it is run through a real
 * validator with Laravel's own rule as the reference: "answers as `multiple_of` does" is the claim a seeded loop pins.
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

/**
 * Runs `$judge` with brick/math's pure-PHP calculator, which an app without the `gmp` and `bcmath` extensions runs (the
 * official `php` images ship neither), whatever extension this machine has. The calculator's class lives in
 * `Brick\Math\Internal\Calculator` before brick/math 0.14 and in `CalculatorRegistry` after, and the names are text so
 * static analysis, which sees only one of them, does not flag the other.
 *
 * @template T
 *
 * @param  Closure(): T  $judge
 * @return T
 */
function withNativeCalculator(Closure $judge): mixed
{
    $registry = class_exists('Brick\Math\Internal\CalculatorRegistry')
        ? 'Brick\Math\Internal\CalculatorRegistry'
        : 'Brick\Math\Internal\Calculator';
    $native = 'Brick\Math\Internal\Calculator\NativeCalculator';

    $registry::set(new $native);

    try {
        return $judge();
    } finally {
        $registry::set(null);
    }
}

/**
 * What `$value` gets from `numeric` beside the rule for `$divisor`: the validator, the seconds it took and the bytes
 * the peak grew by.
 *
 * @return array{LaravelValidator, float, int}
 */
function judgeMeasured(mixed $value, string $divisor): array
{
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);

    $validator = validatorForMultipleOf($value, ['numeric'], new MultipleOf($divisor));
    $validator->fails();

    return [$validator, (hrtime(true) - $start) / 1e9, memory_get_peak_usage() - $before];
}

// A number PHP cannot hold has lost its value once read, so it is refused whatever the divisor. Laravel's `multiple_of`
// throws a `MathException` on `INF`, and a `NAN` or an `INF` would also reach PHP's string casts. The type rule beside
// it keeps its own message. `NAN` is not tried beside `integer`: Laravel's own `integer` turns PHP 8.5's
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

// Laravel's `multiple_of` writes out every digit an exponent stands for: `1e50000000` takes over 2 seconds and 100 MB,
// and `1e80000000` exhausts a 128 MB limit, a fatal that no `catch` turns into a `422`. The rule reads the digits and
// the exponent, so each row costs milliseconds and a few bytes, and the answer is the exact one: `10^n` is a multiple
// of 2, and of `0.25` once `n` is 2, whatever `n` is, and never of 3 or 7. A text whose exponent PHP cannot hold is
// judged like any other (it passes by 2), where Laravel's rule throws.
it('judges a text number with a huge exponent exactly, in milliseconds', function (string $value, string $divisor, bool $passes): void {
    [$validator, $seconds, $growth] = judgeMeasured($value, $divisor);

    expect($validator->passes())->toBe($passes)
        ->and($passes ? [] : $validator->failed()['field'])->toBe($passes ? [] : ['MultipleOf' => [$divisor]])
        ->and($seconds)->toBeLessThan(0.5)
        ->and($growth)->toBeLessThan(16 * 1024 * 1024);
})->with(function (): Generator {
    $rows = [
        ['1e200000000', '2', true], ['1e200000000', '3', false], ['1e200000000', '0.25', true], ['1e200000000', '1.0E+20', true],
        ['1e50000000', '3', false], ['1e80000000', '3', false], ['-1e200000000', '2', true], [' 1E+200000000 ', '2', true],
        ['1e-200000000', '2', false], ['1e-200000000', '1.0E-300', false],
        ['9e9223372036854775807', '3', true], ['9e9223372036854775807', '7', false],
        ['1e99999999999999999999', '2', true], ['1e99999999999999999999', '0.5', true], ['1e99999999999999999999', '3', false],
        ['1e-99999999999999999999', '0.5', false],
        ['1e'.str_repeat('9', 100000), '2', true],
        ['0e999999999999', '7', true], ['0e5', '0', false], ['20e-1', '2', true],
    ];

    foreach ($rows as [$value, $divisor, $passes]) {
        yield (strlen($value) > 40 ? 'a 100000-digit exponent' : $value).' by '.$divisor => [$value, $divisor, $passes];
    }
});

// A long decimal with no exponent at all: brick/math divides by a divisor that no longer fits an `int` by repeated
// subtraction when it runs on its pure-PHP calculator, so Laravel's rule takes 3.6 to 7.5 s on an 8 KB decimal there
// (with the `gmp` or `bcmath` extension it takes milliseconds). Each row runs on the machine's calculator and on the
// native one, since the claim is the same on both: time that grows with the text's length.
it('judges a long number in time that grows with its length, whatever calculator brick/math has', function (string $value, string $divisor, bool $passes): void {
    foreach ([false, true] as $native) {
        $judge = fn (): array => judgeMeasured($value, $divisor);
        [$validator, $seconds] = $native ? withNativeCalculator($judge) : $judge();

        expect($validator->passes())->toBe($passes)
            ->and($seconds)->toBeLessThan(0.5);
    }
})->with([
    '8000 digits and 25 decimals by 2' => [str_repeat('3', 8000).'.'.str_repeat('3', 25), '2', false],
    '8000 digits and 25 decimals by 0.25' => [str_repeat('3', 8000).'.'.str_repeat('3', 25), '0.25', false],
    '10000 digits by 7' => [str_repeat('2', 10000), '7', false],
    '100000 digits by 2' => [str_repeat('2', 100000), '2', true],
    '100000 digits by 0.25' => [str_repeat('3', 100000), '0.25', true],
    '100000 digits by a divisor of 17 digits' => [str_repeat('2', 100000), '0.12345678901234568', false],
]);

// A divisor of more than 17 digits does not fit the integer arithmetic, and only a divisor someone wrote by hand gets
// there (a generated one is a float's text), so it goes through brick/math and answers as `multiple_of` does.
it('answers as multiple_of does for a divisor of more than 17 digits', function (string $value, string $divisor, bool $passes): void {
    $ours = validatorForMultipleOf($value, ['numeric'], new MultipleOf($divisor));
    $reference = validatorForMultipleOf($value, ['numeric'], 'multiple_of:'.$divisor);

    expect($ours->passes())->toBe($passes)
        ->and($ours->errors()->toArray())->toBe($reference->errors()->toArray())
        ->and($ours->failed())->toBe($reference->failed());
})->with([
    'a multiple of an integer' => ['2469135780246913578024690', '1234567890123456789012345', true],
    'not a multiple of an integer' => ['1234567890123456789012346', '1234567890123456789012345', false],
    'a multiple of a decimal' => ['0.3703703670370370367', '0.1234567890123456789', true],
]);

// `is_numeric()` accepts a form feed around a number and brick/math does not, so Laravel's rule throws on the text. It
// is refused, as it always was: a text the reader cannot take is no number, whatever `numeric` says of it.
it('refuses a text that PHP takes for a number and brick/math cannot read', function (string $value): void {
    $validator = validatorForMultipleOf($value, ['numeric'], new MultipleOf('2'));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->get('field'))->toBe(['The field field must be a multiple of 2.']);
})->with([
    'a form feed before the digits' => "\f4",
    'a form feed after the digits' => "4\f",
]);

// 17 digits is the widest divisor the integer arithmetic takes, one digit of the number at a time: the remainder
// reaches the divisor's own size, so a wider chunk overflows an `int` into a float and gives a wrong answer. The
// values sit right at the edge: multiples of 99999999999999999 and the number just after one.
it('answers as multiple_of does for a divisor of 17 digits', function (string $value, bool $passes): void {
    $ours = validatorForMultipleOf($value, ['numeric'], new MultipleOf('99999999999999999'));
    $reference = validatorForMultipleOf($value, ['numeric'], 'multiple_of:99999999999999999');

    expect($ours->passes())->toBe($passes)
        ->and($ours->errors()->toArray())->toBe($reference->errors()->toArray());
})->with([
    'three times it' => ['299999999999999997', true],
    'a multiple whose remainder passes 9.2e16 midway' => ['19299999999999999807', true],
    'one more than it' => ['19299999999999999808', false],
    'one more than three times it' => ['299999999999999998', false],
    'a number of 340 nines' => [str_repeat('9', 340), true],
    'a number of 340 nines then a seven' => [str_repeat('9', 340).'7', false],
]);

// Past the exponent the rule can read (a quadrillion), two exponents that differ look equal, and "equal" would pass a
// value that is not a multiple. Only a divisor someone wrote by hand reaches it (a float's text has a small exponent),
// but `MultipleOf` is public, and such a divisor was always refused (Laravel's rule throws on it): never a wrong pass.
// It is the exponent as written that counts: the divisor's digits (`0.1e...`, `10e-...`) shift it back under the clamp.
it('refuses a value judged by a divisor whose exponent is past what it can read', function (string $value, string $divisor): void {
    $validator = validatorForMultipleOf($value, ['numeric'], new MultipleOf($divisor));

    expect($validator->fails())->toBeTrue()
        ->and($validator->failed()['field'])->toBe(['MultipleOf' => [$divisor]]);
})->with([
    'both exponents past it, the divisor larger' => ['1e88888888888888888888', '1e99999999999999999999'],
    'both exponents just past it, the divisor larger' => ['1e1000000000000000', '1e1000000000000001'],
    'both negative exponents past it, the divisor larger' => ['1e-99999999999999999999', '1e-88888888888888888888'],
    'a decimal divisor whose written exponent is past it, shifted back under it by its digits' => ['1e88888888888888888888', '0.1e99999999999999999999'],
    'a decimal divisor just past it, the value past it too' => ['1e1000000000000000000', '0.1e1000000000000000005'],
    'a divisor with a trailing zero whose negative exponent is past it, the value just under' => ['1e-999999999999999', '10e-99999999999999999999'],
    'a divisor with a trailing zero whose negative exponent is just past it' => ['1e-999999999999999', '10e-1000000000000000'],
]);

// The reference for "answers as `multiple_of` does" is Laravel's own rule, run on a seeded set of numbers (signs,
// leading and trailing zeros, points, exponents, whitespace) and on the texts that are almost numbers, by divisors of
// every shape. One loop: a dataset of 12,000 rows would cost more in bookkeeping than the checks do. It collects the
// pairs that differ, so a failure names them. It skips the pairs where Laravel throws (the texts whose exponent PHP
// cannot hold), which the first test judges.
it('answers as multiple_of does on a seeded set of numbers', function (): void {
    mt_srand(214);

    $values = ['', '1e', '.', '5.', '.5', '1.e3', "\f4", "4\f", "7\n", ' 7 ', '0x1A', '1_000', '--4', '+-4', '0e999999', '20e-1', '200e-2', '4e00001',
        '1e400', '-0', '+0.0', '00012', '1E3', '1e+3', 'abc', 4, 4.5, -6, 0, 0.0, 1.2, 1e308, PHP_INT_MAX, true, false, null, []];

    for ($i = 0; $i < 1000; $i++) {
        $values[] = ['', '-', '+'][mt_rand(0, 2)]
            .mt_rand(0, 10 ** mt_rand(0, 6))
            .(mt_rand(0, 1) ? '.'.mt_rand(0, 99999).str_repeat('0', mt_rand(0, 2)) : '')
            .(mt_rand(0, 1) ? ['e', 'E'][mt_rand(0, 1)].['', '+', '-'][mt_rand(0, 2)].mt_rand(0, 40) : '');
    }

    $mismatches = [];

    foreach ($values as $value) {
        foreach (['2', '0.25', '0.1', '3', '7', '5', '0.001', '1.0E+20', '1.5E-7', '12.5', '0', '0.0625'] as $divisor) {
            $ours = validatorForMultipleOf($value, ['numeric'], new MultipleOf($divisor));

            try {
                $reference = validatorForMultipleOf($value, ['numeric'], 'multiple_of:'.$divisor);
                $same = $ours->errors()->toArray() === $reference->errors()->toArray() && $ours->failed() === $reference->failed();
            } catch (Throwable) {
                continue;
            }

            if (! $same) {
                $mismatches[] = json_encode($value).' by '.$divisor;
            }
        }
    }

    expect($mismatches)->toBe([]);
});
