<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * "An integer, or a float whose value is exactly a whole number."
 *
 * A generated request puts it on an integer of a body that is JSON and nothing else. JSON Schema 2020-12 reads `1.0`
 * and `1e2` as integers, and `"42"` and `true` as no number at all. Laravel's own rules cannot say that:
 * `integer:strict` is `is_int()` and refuses `1.0`, and the loose `integer` reads a float as text with `precision` (14)
 * significant digits, so `2.9999999999999996` becomes `"3"` and passes, and the DTO's `(int)` then makes it `2`. This
 * one compares the float itself.
 *
 * **The range is a 64-bit integer's**, as it is for the loose `integer`: a float at or past 2^63 is refused, because
 * `(int)` on it is a warning and a wrapped number.
 *
 * A plain rule, so Laravel skips it for an absent key and for `""`, as it does `integer`. A rule of Laravel's named
 * `numeric` goes beside it in a generated request: Laravel finds the number `min`, `max`, `gt`, `lt` and `multiple_of`
 * compare by the name of a rule, and an object has none.
 *
 * **It is public API surface**, because a generated request imports it: the name and namespace are frozen with the rest
 * of what a generated file names.
 *
 * @see docs/guide/code-generation/request-validation.md — "A JSON body keeps its types"
 */
final class WholeNumber implements ValidationRule
{
    /**
     * The first float past the range of a 64-bit integer: 2^63, which a float holds exactly. `INF` and `NAN` fail the
     * range as well, and `NAN` fails `floor($value) === $value` first.
     */
    private const float PAST_INT64 = 9223372036854775808.0;

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            return;
        }

        if (is_float($value) && floor($value) === $value && $value >= -self::PAST_INT64 && $value < self::PAST_INT64) {
            return;
        }

        $fail('validation.integer')->translate();
    }
}
