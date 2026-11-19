---
title:
    "ADR 0020: A field's multipleOf goes through the package's MultipleOf, which answers as multiple_of does and refuses
    a number PHP cannot hold"
audience: Users and contributors
covers: >
    The decision that every field with `multipleOf`, in a body and in the query, gets the package's `MultipleOf` rule in
    place of Laravel's `multiple_of`, that the rule asks Laravel for the check and reports under Laravel's name, that a
    number PHP cannot hold is refused whatever the divisor with no finding, what it leaves open (a number too large for
    a double on a field without `multipleOf`, and a text exponent that exhausts memory), and the alternatives turned
    down.
read_before: >
    Asking why a generated request writes `new MultipleOf('0.25')` and not `multiple_of:0.25`, why a JSON `1e400` is a
    `422` on a field with `multipleOf` though the contract accepts it for some divisors, why the rule reports under
    `MultipleOf`, or changing the rule a `multipleOf` gets.
tags: [decisions, code-generation, compatibility]
---

# ADR 0020: A field's multipleOf goes through the package's MultipleOf, which answers as multiple_of does and refuses a number PHP cannot hold

> **In brief**
>
> - A field with `multipleOf` gets `new MultipleOf('<divisor>')` in place of `multiple_of:<divisor>`, in a body (JSON,
>   form or multipart, at every level) and in the query. It asks Laravel's own check and reports under Laravel's name,
>   so every value `multiple_of` answered gets the same errors, message and `failed()` entry.
> - It also refuses a number PHP cannot hold, on which `multiple_of` throws and the request answered `500`: a JSON
>   `1e400`, which PHP reads as `INF`, and a text number whose exponent is 2^63 - 1 or more. The value is gone once
>   read, so the refusal does not depend on the divisor, and it is a stated limit with no finding, as the others are.
> - It costs one more public class that a generated file imports, a refusal of a few numbers the contract accepts, and
>   two cases it does not close: a number too large for a double on a field without `multipleOf`, and a text exponent
>   large enough to exhaust memory.
> - Turned down: `bail`, a rule placed before `multiple_of`, `Validator::extend()`, a string rule name, the remainder
>   computed by the package, `$fail()`, a float divisor, a rule for JSON fields only, and a finding per field.

**Status:** Accepted · **Card:** [#198](https://github.com/Gcob/lara-spec-first/issues/198) · **Pull request:**
[#224](https://github.com/Gcob/lara-spec-first/pull/224)

## Context

Laravel's `multiple_of` hands the value to brick/math, which cannot read two kinds of number. PHP reads a JSON `1e400`
as `INF`, and brick/math refuses it. And brick/math cannot read a text whose exponent is 2^63 - 1 or more, such as
`1e99999999999999999999`. On both, `multiple_of` throws an `Illuminate\Support\Exceptions\MathException`, and the
request answers `500`. Laravel's other numeric rules (`between`, `min`, `max`, `gt`, `lt`) catch that same exception and
refuse the value with their own message, so `multiple_of` is the one numeric rule that throws.

Neither `bail` nor the other rules of the field stop it. `numeric:strict` passes `INF`, so nothing fails before
`multiple_of` runs, and without `bail` every rule runs anyway.

It is not a JSON-only problem. A generated request reads a body as JSON whatever media type the operation declares, so a
JSON client of a form-only operation hands `INF` to the form field's rules. And a query parameter is always text, but
the overflow text throws too, from a query, from a form field and from a JSON string.

[ADR 0006](./0006-hold-rules-to-the-contract.md) asks that a rule stricter than the contract carry a finding. A number
PHP cannot hold is a case it does not name: `1e400` is a multiple of `0.5`, and `main` already refuses it, with a `500`.

## Decision

**A field with `multipleOf` is `new MultipleOf('<divisor>')`,** where it was `multiple_of:<divisor>`, wherever the
generated request runs it: a body read as JSON, as JSON and a form, as a form or as multipart, at every level (a map's
values and a name judged by shape included), and a query parameter. `MultipleOf` is
`Gcob\LaraSpecFirst\Validation\MultipleOf` and implements Laravel's `ValidationRule` and `ValidatorAwareRule`. One
sentence for a reader: `multipleOf` is the package's `MultipleOf`, and no field writes `multiple_of`.

**It asks Laravel for the check, reports under Laravel's name, and never throws Laravel's exception.** It calls the
validator's own `validateMultipleOf()` inside a `try` that counts a `MathException` as a failure, as `between` does, and
reports a failure with `addFailure()` under `MultipleOf`. So the message, the `:attribute` and `:value` names, a
project's `validation.custom.<field>.multiple_of` line (under a wildcard too), a `messages()` entry keyed `multiple_of`
or `<field>.multiple_of`, and the `failed()` entry are what `multiple_of` gives, and a test holds that parity against
Laravel's own rule on the values `multiple_of` answered.

**A float that is not finite fails first, before Laravel is asked.** The `catch` would cover `INF`, but not `NAN`: it
reaches PHP's string casts inside Laravel's rules, and PHP 8.5 warns "unexpected NAN value was coerced to string". The
handler of a Laravel application turns that warning into an `ErrorException`, which gets past the `catch` for a
`MathException`, and the request answers `500`. Checking the float first also keeps the card's main case from resting on
an exception.

**The divisor is a string, the exact text `multiple_of:` held:** `new MultipleOf('0.25')`, `new MultipleOf('5')`,
`new MultipleOf('1.0E+20')`. It is Laravel's parameter as Laravel receives it, so the check cannot move by a digit, and
`failed()` reports the same parameter. The constructor takes it as a `public readonly` property that the emitter reads.

**A number PHP cannot hold is refused whatever the divisor, as a stated limit with no finding.** `1e400` is a multiple
of `0.5` and `3e400` is a multiple of `3`, but once PHP reads either as `INF` the value is gone, and `INF` can be a
multiple of anything or of nothing. The overflow text is the same: it may well be a multiple of 2, and nothing can check
it. Refusing is the only answer that is never looser than the contract. It is stricter than the contract for some
divisors, and `main` already refuses both values, so nothing narrows. It carries no finding, for the reason
[ADR 0019](./0019-judge-a-json-integer-by-its-value.md) gives for the limits of PHP's numbers: a unique list is not
exact past 2^53, and an integer is judged within the range of a 64-bit integer, and in both the guide states the limit,
a test pins it, and the generated file says nothing, since no keyword of the contract names the case. A number PHP
cannot hold is the same kind of limit. The guide's row for `multipleOf` states it
([the table](../../guide/code-generation/request-validation.md#every-constraint-maps-or-reports)).

**The class is public API.** A generated request imports it, so its name, namespace and constructor are frozen, as
`NullOrAbsent` ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)), `UndeclaredKeys`
([ADR 0014](./0014-judge-undeclared-keys-by-name.md)) and `WholeNumber` (ADR 0019) are.

## Alternatives turned down

- **`bail`.** It does not stop the exception: `numeric:strict` passes `INF`, so nothing fails before `multiple_of`. It
  would also cut every other message of the field.
- **A rule placed before `multiple_of:`.** Without `bail`, every rule runs, so a rule before it cannot keep it from
  running.
- **An upstream fix alone.** A fix in Laravel helps nobody on 12.x or 13.x today, and the package would still need a
  floor that includes it.
- **`Validator::extend()`, overriding `multiple_of` for the whole application.** It changes a rule that the user's own
  code may rely on, for a problem of the package's generated files.
- **A string rule name registered by the provider** (`lsf_multiple_of:`). It needs a registration that a generated file
  silently depends on, and a name no message or `failed()` entry of the project knows.
- **The remainder computed by the package, with `BigDecimal`.** It is a second implementation of Laravel's edge cases
  (text, booleans, an empty string, `null`), and the parity the decision asks for would rest on it.
- **`$fail(...)->translate()`.** It loses the project's `validation.custom.<field>.multiple_of` line and the global
  `messages()` key, and `failed()` names the class.
- **A float divisor** (`new MultipleOf(0.25)`). It reads nicer, but it needs a second formatting rule that agrees with
  the builder's `number()` for every float, where the string is already the text `multiple_of:` held.
- **The JSON-declared fields only.** A form-only operation sent JSON still answered `500`.
- **The body only, with the query keeping `multiple_of`.** A query's text throws too.
- **The `catch` alone, without the non-finite check.** A `NAN` would warn, and PHP 8.5's warning becomes an
  `ErrorException` that is not a `MathException` (see the decision).
- **A bound on a text's exponent before asking Laravel.** It would also close the memory case below, but it is a bound
  to pick and justify, and a bigger change than this one.
- **A finding per field.** It would count a value nobody sends on every field with `multipleOf`, and the limits of PHP's
  numbers carry none.

## Consequences

- **`MultipleOf` joins `NullOrAbsent`, `UndeclaredKeys` and `WholeNumber` in what a generated file imports.** Its name,
  namespace and constructor cannot change without a breaking release.
- **A JSON `1e400` and an overflow text get a `422` where they got a `500`.** For every other value, the generated
  request answers as before, so the CHANGELOG lists it under `Fixed`. A request generated before keeps `multiple_of:`
  until `spec:build` runs again.
- **A few numbers the contract accepts are refused.** `1e400` on a field with `multipleOf: 0.5` is a `422`, and the
  guide says so.
- **What this does not settle.** A number too large for a double, on a field without `multipleOf`, is a separate case
  ([#213](https://github.com/Gcob/lara-spec-first/issues/213)). And a text exponent from the tens of millions up to
  2^63 - 1 (`1e200000000`) makes brick/math build the digits, which exhausts a 128 MB memory limit from about 63 million
  (measured: `1e62000000` passes, `1e64000000` is a fatal error that no `catch` turns into a `422`), and smaller
  exponents are slow (`1e50000000` takes about 2.5 seconds and 100 MB)
  ([#214](https://github.com/Gcob/lara-spec-first/issues/214)).
- **Earlier records keep their wording.** ADRs [0007](./0007-keep-json-scalars-strict.md) and
  [0016](./0016-keep-json-scalars-typed.md) say Laravel finds the number `multiple_of` compares by the name of a rule,
  which is not so (it reads the value itself). They are dated, and
  [the guide](../../guide/code-generation/request-validation.md#a-json-body-keeps-its-types) holds the rule today.
