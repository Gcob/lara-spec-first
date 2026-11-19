<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

use Brick\Math\BigInteger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;

/**
 * "A multiple of the divisor, judged from the number's digits, and a number PHP cannot hold is refused."
 *
 * A generated request puts it on every field with `multipleOf`, in a body and in the query. It answers as Laravel's
 * `multiple_of` does, but `multiple_of` writes out every digit an exponent stands for: `1e50000000` takes over 2
 * seconds, `1e80000000` exhausts a 128 MB memory limit (a fatal error, which answers `500`), and an exponent brick/math
 * cannot read throws. Without the `gmp` and `bcmath` extensions it also divides a long decimal by repeated subtraction,
 * which takes seconds for an 8 KB one. This rule reads the digits and the exponent and does the arithmetic in PHP
 * integers, so its time grows with the length of the text and never with the size of the exponent, whatever version of
 * brick/math or calculator the app has.
 *
 * **What still comes from Laravel.** What is a number is what `numeric` says (PHP's `is_numeric()`), the text is
 * trimmed as Laravel trims it and read with brick/math's own grammar, and a failure is added with `addFailure()` as
 * `MultipleOf`, so the message, the `:value` and `:attribute` names, a project's
 * `validation.custom.<field>.multiple_of` line, a `messages()` entry keyed `multiple_of` and the `failed()` entry are
 * what `multiple_of` gives. A `$fail(...)` would report under this class's name and lose all of them.
 *
 * **A float that is not finite fails first.** `NAN` reaches PHP's string casts, and PHP 8.5 warns "unexpected NAN value
 * was coerced to string", which Laravel's handler turns into an `ErrorException`.
 *
 * **A number PHP cannot hold is refused whatever the divisor.** `1e400` is a multiple of `0.5`, but once a JSON body is
 * read as `INF` its value is gone, and `INF` can be a multiple of anything or of nothing. Refusing is the only answer
 * that is never looser than the contract. This is the one limit: a text keeps its digits, so it is judged.
 *
 * **The divisor is Laravel's parameter as Laravel receives it**: the text `multiple_of:` held, `'0.25'` and `'5'`. A
 * divisor of more than 17 digits, which no generated request writes (a float's text has at most 17), goes through
 * brick/math, as slowly as Laravel's own check on its pure-PHP calculator.
 *
 * **It is public API surface**, because a generated request imports it: the name, namespace and constructor are frozen
 * with the rest of what a generated file names.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final class MultipleOf implements ValidationRule, ValidatorAwareRule
{
    /** brick/math's grammar for a number (`BigNumber::PARSE_REGEXP_NUMERICAL`), the same from 0.11 to 1.0. */
    private const string NUMBER = '/^[-+]?(?<integral>[0-9]+)?(?<point>\.)?(?<fractional>[0-9]+)?(?:[eE](?<exponent>[-+]?[0-9]+))?\z/';

    /**
     * Every other exponent is a text length or a float's, far below it, so past it only the sign is left. Two exponents
     * past it cannot be told apart, so a divisor whose exponent, as written, reaches it refuses the value, whatever its
     * digits shift the exponent to.
     */
    private const int EXPONENT_CAP = 10 ** 15;

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

        // Why: `is_numeric()` is what Laravel's `numeric` does without `strict`. `validateNumeric()` itself types its
        // parameters differently from one Laravel to the next, and static analysis cannot satisfy them all.
        $passes = is_numeric($value)
            && is_numeric($this->divisor)
            && self::isMultiple(is_string($value) ? trim($value) : (string) $value, trim($this->divisor));

        if (! $passes) {
            $this->validator->addFailure($attribute, 'MultipleOf', [$this->divisor]);
        }
    }

    /**
     * Writes the value as `m * 10^e` and the divisor as `q * 10^f`, each digit string without leading or trailing
     * zeros. Zero is a multiple of every divisor but zero. A value with fewer trailing zeros than the divisor (`e < f`)
     * is no multiple, since `m` ends in a nonzero digit. Otherwise `m` followed by `e - f` zeros is divided by `q`, and
     * more than `4 * digits of q` zeros change nothing: they already hold every factor 2 and 5 of `q`, and 10 shares no
     * other factor with it.
     */
    private static function isMultiple(string $value, string $divisor): bool
    {
        $read = self::read($value);
        $divisorRead = self::read($divisor);

        if ($read === null || $divisorRead === null) {
            return false;
        }

        [$digits, $exponent] = $read;
        [$divisorDigits, $divisorExponent, $divisorClamped] = $divisorRead;

        if ($digits === '') {
            return $divisorDigits !== '';
        }

        if ($divisorDigits === '' || $exponent < $divisorExponent || $divisorClamped) {
            return false;
        }

        $zeros = min($exponent - $divisorExponent, 4 * strlen($divisorDigits));

        return self::divides($digits.str_repeat('0', $zeros), $divisorDigits);
    }

    /**
     * @return array{string, int, bool}|null the significant digits, the power of ten that follows them, and whether the
     *                                       exponent as written reached the clamp
     */
    private static function read(string $text): ?array
    {
        if (preg_match(self::NUMBER, $text, $match, PREG_UNMATCHED_AS_NULL) !== 1 || ($match['integral'] === null && $match['fractional'] === null)) {
            return null;
        }

        $fractional = $match['fractional'] ?? '';
        $digits = ltrim(($match['integral'] ?? '').$fractional, '0');
        $kept = rtrim($digits, '0');

        $written = self::exponent($match['exponent'] ?? '0');

        return [$kept, $written - strlen($fractional) + strlen($digits) - strlen($kept), abs($written) === self::EXPONENT_CAP];
    }

    private static function exponent(string $text): int
    {
        $digits = ltrim($text, '+-0');
        $value = strlen($digits) > 15 ? self::EXPONENT_CAP : min((int) $digits, self::EXPONENT_CAP);

        return str_starts_with($text, '-') ? -$value : $value;
    }

    /**
     * Horner's rule over the digits, in chunks small enough that `remainder * 10^chunk + chunk` stays below 10^18,
     * inside a PHP `int`. Walks the string with `substr()`: splitting a 7 MB text into an array would cost more than
     * the memory limit.
     */
    private static function divides(string $number, string $divisor): bool
    {
        if (strlen($divisor) > 17) {
            return BigInteger::of($number)->mod(BigInteger::of($divisor))->isZero();
        }

        $modulus = (int) $divisor;
        $width = 18 - strlen($divisor);
        $remainder = 0;

        for ($at = 0, $length = strlen($number); $at < $length; $at += $width) {
            $chunk = substr($number, $at, $width);
            $remainder = ($remainder * (int) (10 ** strlen($chunk)) + (int) $chunk) % $modulus;
        }

        return $remainder === 0;
    }
}
