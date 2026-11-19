---
title:
    "ADR 0021: A field's multipleOf is judged exactly from the number's digits, and only a number PHP cannot hold is
    refused"
audience: Users and contributors
covers: >
    The decision that the package's `MultipleOf` rule decides from a number's digits and exponent with PHP integers,
    where ADR 0020 asked Laravel's `multiple_of` check, so a text with a huge exponent or many digits is judged in time
    that grows with its length, what it keeps from ADR 0020 (the class and its frozen API, every field, the divisor as
    text, the report under `MultipleOf`, the refusal of a float PHP reads as `INF` or `NAN`), what it leaves open, and
    the alternatives turned down.
read_before: >
    Asking why `1e99999999999999999999` passes a field with `multipleOf: 2`, why the rule no longer calls Laravel's
    `multiple_of` check, why it stays fast without the `gmp` and `bcmath` extensions, or changing how the rule reads a
    number.
tags: [decisions, code-generation, compatibility]
---

# ADR 0021: A field's multipleOf is judged exactly from the number's digits, and only a number PHP cannot hold is refused

> **In brief**
>
> - `MultipleOf` decides from the number's digits and exponent, with PHP integers, and no longer asks Laravel's
>   `multiple_of` check. A text such as `1e200000000` or `1e99999999999999999999` is judged exactly: `1e200000000` is a
>   multiple of 2 and `1e99999999999999999999` is one too, so both pass, where Laravel's check took seconds, exhausted
>   memory or threw.
> - The time grows with the length of the text and never with its exponent, on every version of brick/math the package
>   supports and with or without the `gmp` and `bcmath` extensions.
> - Every other value gets `multiple_of`'s answer, message and `failed()` entry, which a seeded test against Laravel's
>   own rule holds. What is a number, and how a text is trimmed and read, stays Laravel's and brick/math's.
> - The only value refused whatever the divisor is a float PHP reads as `INF` or `NAN`, as before. This replaces
>   [ADR 0020](./0020-check-multipleof-through-the-package.md), whose decisions about the class stand and are restated.
> - Turned down: Laravel's exponent range, judging exactly only past a threshold, a cap on the digits, brick/math's
>   `mod()` for every divisor, and a `require` on brick/math.

**Status:** Accepted · **Card:** [#214](https://github.com/Gcob/lara-spec-first/issues/214) · **Pull request:**
[#227](https://github.com/Gcob/lara-spec-first/pull/227)

## Context

[ADR 0020](./0020-check-multipleof-through-the-package.md) put the package's `MultipleOf` on every field with
`multipleOf` and kept the check itself with Laravel: the rule called the validator's own `validateMultipleOf()` and
counted a `MathException` as a failure. It named two cases it left open, and this is the second.

Laravel's check hands the text to brick/math, which writes out every digit the exponent stands for. A positive exponent
becomes that many zeros, and a negative one pads the divisor's scale to match. So the cost grows with the exponent's
value, and a request of a few bytes can pay for it. Measured on Laravel's own rule (`numeric` and `multiple_of:3`) under
a 128 MB memory limit, and the same on brick/math from 0.11 to 1.0:

| Text                     | Laravel's `multiple_of`         |
| ------------------------ | ------------------------------- |
| `1e50000000`             | over 2 seconds and about 100 MB |
| `1e80000000`             | a memory fatal error            |
| `1e99999999999999999999` | a `MathException`               |

A fatal error is no exception. The `catch` of ADR 0020 cannot turn it into a `422`, and the worker answers `500`. Where
an exponent becomes too large to write out at all depends on the version of brick/math: one refuses it with an
exception, another tries and runs out of memory. So no bound on the text can be stated once for every install. ADR 0020
stated one, and it was wrong on some of them.

There is a second way to tie up a worker, with no exponent in it. Without the `gmp` and `bcmath` extensions, which the
official PHP images do not ship, brick/math divides in pure PHP, and past a divisor that fits a PHP integer it does so
by repeated subtraction. Laravel pads the divisor to the value's decimals, so a text of 8 KB with 25 decimals (it fits a
query string) takes 3.6 to 7.5 seconds through Laravel's check, on every version of brick/math from 0.11 to 1.0. On 0.11
to 0.12.1 even a plain 10,000-digit integer takes seconds there, because `BigInteger::mod()` is quadratic. With `gmp` or
`bcmath`, each of these takes milliseconds.

Laravel's own way with a huge exponent is a range. Its size rules (`min`, `max`, `between`, `gt`, `lt`, `size`) refuse
an exponent outside `[-1000, 1000]` and answer `false`. `multiple_of` does not use it.

[ADR 0006](./0006-hold-rules-to-the-contract.md) asks that a rule stricter than the contract carry a finding. A text
with a huge exponent is a number the contract accepts and PHP can hold as text. Refusing it would be a rule stricter
than the contract on a value the package can judge: a gap in the contract, not one of the limits of PHP's numbers.

## Decision

**`MultipleOf` judges the number from its digits and exponent, exactly, in PHP integers.** It writes the value as
`m·10^e` and the divisor as `q·10^f`, where `m` and `q` are digit strings with no leading or trailing zero. Then:

1. A value of zero is a multiple of every divisor but zero, and nothing is a multiple of zero. These are Laravel's own
   answers.
2. If `e` is less than `f`, the value is not a multiple: `m` ends in a digit that is not zero, so `m·10^(e-f)/q` cannot
   be a whole number.
3. Otherwise it is a multiple when `m`, followed by zeros, is divisible by `q`. The zeros are `e - f` of them, but never
   more than four times the digits of `q`: past that, more zeros change nothing, since they already hold every factor 2
   and 5 of `q`, and 10 shares no other factor with it. So at most a few dozen zeros are appended, whatever the
   exponent.
4. The divisibility is Horner's rule over the digits, in chunks of `18 - digits of q` digits, so each step
   `(remainder × 10^chunk + chunk) % q` stays inside a PHP `int`. The text is walked with `substr()`, never split into
   an array. A divisor of more than 17 digits goes through `BigInteger::mod()` instead. No generated request writes one,
   since its divisor is a float's text and has at most 17 significant digits.

The exponent is read as a PHP `int`, clamped far above any length a request can carry. Every other term is a text length
or a float's exponent, so past the clamp only the sign matters, and the clamp keeps it. A divisor whose own exponent, as
written, reaches the clamp, in either sign, refuses the value (its digits do not shift it back under:
`0.1e1000000000000000000` is refused too), since two exponents past it cannot be told apart. A value of zero needs no
exponent to compare, and stays a multiple of any divisor but zero.

**One path for every value, with no threshold.** The rule does not switch to the exact method past some exponent. A
threshold would leave the long decimal, which has no exponent, slow on the pure-PHP calculator, and it would give the
rule two ways to answer. "It decides from the digits" is one sentence a reader can check against the code.

**What still comes from Laravel.** What is a number is what `numeric` says without `strict` (PHP's `is_numeric()`), so a
boolean, a list, `"abc"` and `"0x1A"` fail exactly where they failed. The text is trimmed as Laravel trims it, and read
with brick/math's own grammar (the same language from 0.11 to 1.0), so a text brick/math refuses, such as a form feed
before a digit, fails here too. A failure is added with `addFailure()` under `MultipleOf`. A seeded test compares the
rule with Laravel's `multiple_of` on generated values (signs, leading and trailing zeros, points, fractions, `e` and `E`
exponents, whitespace and a list of special texts) beside `numeric`, on errors and on `failed()`, and holds it to the
same answer on every pair where Laravel answers. The pairs where Laravel throws are judged by their own rows.

**A text whose exponent PHP cannot hold is judged, no longer refused.** `1e99999999999999999999` is a multiple of 2,
since `10^n` is one for every `n` of 1 or more, so it passes. Divided by 3, it fails. The text bound ADR 0020 stated is
gone with the refusal, and the guide and the matrix name none. The one limit left is the float: PHP reads a JSON `1e400`
as `INF`, and the value is gone once read, so `INF` can be a multiple of anything or of nothing. It fails whatever the
divisor, with no finding, for the reason [ADR 0019](./0019-judge-a-json-integer-by-its-value.md) gives for the limits of
PHP's numbers: the guide states it, a test pins it, and no keyword of the contract names the case. A float that is not
finite fails before anything else, so a `NAN` never reaches a string cast, which PHP 8.5 warns on and a Laravel handler
turns into an `ErrorException`.

**No bound and no configuration.** The work grows with the text's length and never with its exponent. Measured under 128
MB: `1e200000000`, a million-digit exponent, plain integers of 100,000 digits and the 8 KB decimal take 4 to 13
milliseconds on every brick/math from 0.11 to 1.0, with the pure-PHP calculator too. A text of 7 million digits takes 31
to 56 milliseconds, and 0.21 to 0.25 seconds by a divisor of 17 digits, where each chunk holds one digit. What caps the
length is outside the rule: PHP's `post_max_size` for a `POST` body, the web server's limits for a `PUT` or `PATCH` body
and the query, and `memory_limit`. Within those, the rule is linear in the text's length, and within a small factor of
Laravel's check where brick/math has GMP (by a divisor of 17 digits, 1.1 to 1.6 times slower: 0.223 s against 0.198 s on
7 million digits), on every version of brick/math tried. Nothing is left for an app to declare.

**What carries over from ADR 0020.** The class is `Gcob\LaraSpecFirst\Validation\MultipleOf`, implements Laravel's
`ValidationRule` and `ValidatorAwareRule`, and is public API: a generated request imports it, so its name, namespace and
constructor are frozen, as those of `NullOrAbsent` ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)),
`UndeclaredKeys` ([ADR 0014](./0014-judge-undeclared-keys-by-name.md)) and `WholeNumber` (ADR 0019) are. Every field
with `multipleOf` gets `new MultipleOf('<divisor>')` in place of `multiple_of:<divisor>`, in a body read as JSON, as a
form or as multipart, at every level, and in the query. The divisor is a string, the exact text `multiple_of:` held, so
`failed()` reports Laravel's parameter. The rule reports under `MultipleOf`, so the message, the `:attribute` and
`:value` names, a project's `validation.custom.<field>.multiple_of` line (under a wildcard too), a `messages()` entry
keyed `multiple_of` or `<field>.multiple_of`, and the `failed()` entry are what `multiple_of` gives.

## Alternatives turned down

- **Laravel's exponent range.** Refusing an exponent outside `[-1000, 1000]` is Laravel's way, and the simplest to
  explain. It is stricter than the contract: `1e1001` is a number the contract accepts, and the refusal would be a rule
  to state or to report for a value the package can judge. It also leaves the long decimal at seconds on the pure-PHP
  calculator, where the rule decides from the digits and closes both cases.
- **Exact only past an exponent threshold.** It leaves the long decimal slow, as above, and gives the rule two methods,
  one of them reached only by an unusual text.
- **A cap on the digits or on the exponent.** A bound to pick and justify, and one that depends on the version of
  brick/math in the app, which is the reason no text bound could be stated in ADR 0020.
- **`BigInteger::mod()` for every divisor.** It is shorter, and it is quadratic on brick/math 0.11 to 0.12.1 without
  `gmp` or `bcmath`, which is the lowest Laravel 12 install the package tests. The integer path keeps CI's lowest legs
  in milliseconds. The divisor of more than 17 digits is the one case left to `mod()`.
- **A `require` on brick/math from 0.12.2.** The package would then have to follow the range Laravel allows at each of
  its releases, or block an upgrade, to cover one fallback for a divisor nobody generates. brick/math reaches the
  package through Laravel's validator, which requires it, as `Illuminate\Validation` itself does without being declared.
  It is not the case of `symfony/yaml` and `symfony/process` in [the stack](../stack.md), which the package calls on its
  own path.
- **The remainder computed by the package, restated.** ADR 0020 turned it down because it is a second implementation of
  Laravel's edge cases. The reason that stays with Laravel stays: `numeric`, Laravel's trim and brick/math's grammar
  decide what is a number, and a seeded test pins every other answer. What changed is that Laravel's check is stricter
  than the contract and leaves the long decimal at seconds.
- **ADR 0020's own alternatives** (`bail`, a rule placed before `multiple_of`, `Validator::extend()`, a string rule
  name, `$fail(...)->translate()`, a float divisor, a rule for JSON fields only, the body only, a finding per field)
  stay turned down, for the reasons it gives.

## Consequences

- **A text with a huge exponent, or many digits, gets an answer in milliseconds.** `1e99999999999999999999` on a field
  with `multipleOf: 2` is a `200` where it was a `500`, and by 3 a `422`. The CHANGELOG lists it under `Fixed`: every
  value Laravel answered gets the same answer, message and `failed()` entry, and a request that took seconds or answered
  `500` gets a quick answer.
- **The arithmetic is the package's.** A seeded test against Laravel's `multiple_of` pins it, and a change to how the
  rule reads a number has to keep that test green. A request generated before keeps `multiple_of` until the next
  `spec:build`.
- **A divisor of more than 17 digits goes through `BigInteger::mod()`.** On the pure-PHP calculator, that is as slow as
  Laravel's own check. No generated request writes one: the divisor is a float's text.
- **What this does not settle.** Laravel's range of `[-1000, 1000]` on `minimum`, `maximum`, `exclusiveMinimum` and
  `exclusiveMaximum` is stricter than the contract and stated nowhere
  ([#226](https://github.com/Gcob/lara-spec-first/issues/226)). A number too large for a double, on a field without
  `multipleOf`, is a separate case ([#213](https://github.com/Gcob/lara-spec-first/issues/213)).
- **ADR 0020 keeps its wording.** It is dated, and its status line points here.
