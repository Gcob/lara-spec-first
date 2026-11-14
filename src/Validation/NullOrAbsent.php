<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * "Nothing here but `null`, or no key at all."
 *
 * A generated request puts it on a nullable recursive field below the depth the schema states: the contract allows a
 * `null` there and the request refuses everything else, since nothing below the depth is validated. No rule Laravel
 * ships says it. `missing` refuses `null`, `prohibited` lets `""` and `[]` through, and `nullable` does not skip an
 * implicit rule.
 *
 * **Implicit**, so Laravel calls it for an absent key and for `""` as well, which a plain rule is skipped for. An
 * absent key reaches it as `null`, which passes. Over HTTP the `""` of a JSON body, a form or a query string has become
 * `null` by the time any rule runs (`ConvertEmptyStringsToNull`), so there it passes; a validator fed the value
 * directly refuses it.
 *
 * **It is public API surface**, because a generated request imports it: the name and namespace are frozen with the rest
 * of what a generated file names.
 *
 * @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"
 */
final class NullOrAbsent implements ValidationRule
{
    public bool $implicit = true;

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null) {
            // The line a closed object's undeclared key answers with, so one wording and one translation serve both.
            $fail('validation.prohibited')->translate();
        }
    }
}
