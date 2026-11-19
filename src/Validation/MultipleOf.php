<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Exceptions\MathException;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;

/**
 * "A multiple of the divisor, as Laravel's `multiple_of` says, and a number PHP cannot hold is refused."
 *
 * A generated request puts it on every field with `multipleOf`, in a body and in the query. Laravel's `multiple_of`
 * throws a `MathException`, which answers `500`, on two values: a JSON `1e400`, which PHP reads as `INF`, and a text
 * number whose exponent does not fit in 64 bits (`1e99999999999999999999`), which brick/math cannot read. Laravel's
 * `between` and size rules catch that exception and refuse the value, and so does this rule.
 *
 * **It asks Laravel, and reports under Laravel's name.** The check is `validateMultipleOf()` of the validator the rule
 * runs in, and a failure is added with `addFailure()` as `MultipleOf`, so the message, the `:value` and `:attribute`
 * names, a project's `validation.custom.<field>.multiple_of` line, a `messages()` entry keyed `multiple_of` and the
 * `failed()` entry are what `multiple_of` gives. A `$fail(...)` would report under this class's name and lose all of
 * them.
 *
 * **A float that is not finite fails first.** `NAN` and `INF` reach PHP's string casts inside Laravel's rules, and PHP
 * 8.5 warns "unexpected NAN value was coerced to string", which Laravel's handler turns into an `ErrorException` that
 * the `catch` does not cover.
 *
 * **A number PHP cannot hold is refused whatever the divisor.** `1e400` is a multiple of `0.5`, but once read as `INF`
 * its value is gone, and `INF` can be a multiple of anything or of nothing. Refusing is the only answer that is never
 * looser than the contract.
 *
 * **The divisor is Laravel's parameter as Laravel receives it**: the text `multiple_of:` held, `'0.25'` and `'5'`.
 *
 * **It is public API surface**, because a generated request imports it: the name, namespace and constructor are frozen
 * with the rest of what a generated file names.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final class MultipleOf implements ValidationRule, ValidatorAwareRule
{
    private Validator $validator;

    public function __construct(public readonly string $divisor) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_float($value) && ! is_finite($value)) {
            $this->validator->addFailure($attribute, 'MultipleOf', [$this->divisor]);

            return;
        }

        try {
            $passes = $this->validator->validateMultipleOf($attribute, $value, [$this->divisor]);
        } catch (MathException) {
            $passes = false;
        }

        if (! $passes) {
            $this->validator->addFailure($attribute, 'MultipleOf', [$this->divisor]);
        }
    }
}
