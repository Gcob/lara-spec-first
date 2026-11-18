---
title: "ADR 0016: A JSON body's scalars keep their types, and a whole number written as a float is an integer"
audience: Users and contributors
covers: >
    The decision that a JSON integer is judged by the package's own `WholeNumber` rule, so it accepts a whole number
    written as a float (`1.0`, `1e2`) and still refuses `"42"`, `true` and a float a hair off a whole number, that a
    unique list of numbers is compared by value, what of ADR 0007 still holds (strict numbers and booleans in a JSON
    body, loose text fields, a body that is JSON and a form, the Laravel constraint), and the alternatives turned down.
read_before: >
    Asking why a JSON client's `1.0` is a `200` for an integer and `"42"` is not, why a generated request imports
    `WholeNumber`, why a unique list calls `1` and `1.0` one element, or changing the rule a JSON integer gets.
tags: [decisions, code-generation, compatibility, laravel, versions]
---

# ADR 0016: A JSON body's scalars keep their types, and a whole number written as a float is an integer

> **In brief**
>
> - A JSON integer is judged by `WholeNumber`, a rule of the package's: an `int`, or a `float` whose value is exactly
>   whole. `1.0`, `1e2` and `1e14` pass, as they do for JSON Schema 2020-12, and `"42"`, `true`, `1.5` and
>   `2.9999999999999996` are refused.
> - Laravel's own rules cannot do it: `integer:strict` refuses `1.0`, and the loose `integer` reads a float as text with
>   14 significant digits, so a float a hair off a whole number passes it, and `dto()` truncates it.
> - A list with `uniqueItems` compares numbers by value (`distinct`), so `[1, 1.0]` is a duplicate. Past float precision
>   the comparison is not exact, and that limit is stated, not reported.
> - The rest of ADR 0007 holds: strict numbers and booleans in a JSON body, loose rules for text, the body that is JSON
>   and a form, the Laravel constraint.
> - Turned down: keeping `integer:strict`, and Laravel's `integer` beside `numeric:strict`.

**Status:** Accepted · **Cards:** [#35](https://github.com/Gcob/lara-spec-first/issues/35),
[#107](https://github.com/Gcob/lara-spec-first/issues/107) and
[#173](https://github.com/Gcob/lara-spec-first/issues/173) · **Pull requests:**
[#105](https://github.com/Gcob/lara-spec-first/pull/105), [#135](https://github.com/Gcob/lara-spec-first/pull/135) and
[#195](https://github.com/Gcob/lara-spec-first/pull/195)

## Context

[ADR 0007](./0007-keep-json-scalars-strict.md) gave a JSON integer `integer:strict`, which is `is_int()` on the decoded
value. That refused `"42"` and `true`, as the contract does, and it also refused `1.0` and `1e2`, which JSON Schema
2020-12 reads as integers. The decision took the refusal as the price of the strict rule, and said so in a finding.

It assumed that no Laravel rule accepts `1.0` and still refuses `"42"`. That is half false: Laravel's loose `integer`
accepts a whole number written as a float, and `numeric:strict` refuses what is not a JSON number, so `integer` beside
`numeric:strict` accepts `1.0`, `1e2` and `-0.0` and refuses `"42"` and `true`. But it is not exact. `integer` is PHP's
`FILTER_VALIDATE_INT` on the float read as text, and PHP writes a float with 14 significant digits: `2.9999999999999996`
(what JavaScript gives for `0.3 / 0.1`), `4.999999999999999` and `5.00000000000001` become `3`, `5` and `5` and pass,
and `dto()` then casts them to `2`, `4` and `5`. The same text read refuses a whole float of 1e14 or more, which PHP
writes with an exponent. `multiple_of:1` and `decimal:0` read the same text and close neither.

Moving to a rule that accepts `1.0` also changes what a unique list does. `distinct:strict` called `1` and `1.0` two
elements, so a list with `uniqueItems` would let `[1, 1.0]` through, which the contract calls a duplicate. The same gap
already existed for a list of numbers (`[1, 1.0]`, `[10, 1e1]`, `[0, -0.0]`) and for a form list (`1` and `+1`, `1` and
`1.0`).

## Decision

**A JSON integer is `WholeNumber`, at every level** (`a.b`, `items.*`, a map's values) of a body that is JSON and
nothing else. It passes an `int`, and a `float` whose value is exactly whole (`floor($value) === $value`) inside the
range of a 64-bit integer, since `(int)` on a float past it warns and wraps. It refuses everything else: a fraction, a
float a hair off a whole number, `"42"`, `true`, `null`, `INF` and `NAN`. It compares the float itself, so no float is
read as text. A generated request imports `Gcob\LaraSpecFirst\Validation\WholeNumber`, which is public API like
`NullOrAbsent` and `UndeclaredKeys`, with its name frozen.

**`numeric` goes beside it, and `WholeNumber` comes first.** Laravel finds the number `min`, `max`, `gt`, `lt` and
`multiple_of` compare by the name of a rule (`numeric`, `integer`, `decimal`), and an object has none, so without it
they would compare the length of the text. The first message is `WholeNumber`'s, "must be an integer", and a value that
is no number at all (`true`, `false`, `"abc"`, `[]`) gets `numeric`'s as well. No `bail`: that is Laravel's default, and
it would also hide a `min` or `max` message.

**`validated()` keeps the float as sent, and `dto()` casts it to an `int`.** That is how a query parameter's text
already works: `validated()` returns what the client wrote, and the typed path is the DTO. With no near-whole float let
through, the cast loses nothing.

**A list with `uniqueItems` compares integers and numbers by value.** Its element rule is Laravel's `distinct` in a JSON
body and in a form, and `distinct:strict` stays for strings and booleans. A string has no second spelling, nor does a
boolean in JSON (`"1"` and `"01"` are two strings), but a form's boolean has two, `"true"` and `"1"`, which
`distinct:strict` calls two elements: a gap this decision leaves open
([#197](https://github.com/Gcob/lara-spec-first/issues/197)). An array query parameter has no element rule, so nothing
changes for it.

**The rest of [ADR 0007](./0007-keep-json-scalars-strict.md) holds, and is restated here:**

- A JSON body's numbers and booleans are `numeric:strict` and `boolean:strict`, at every level.
- A query parameter and a form or multipart field keep the loose `integer` and `numeric`, and a text boolean, because
  every value they carry is text.
- A form client of an operation that declares only JSON gets the JSON rules.
- A body that is JSON and a form at once keeps the loose rules, so a JSON client's `"42"`, `true` and a float within 14
  digits of a whole number get through an integer, and a finding per field says so. A runtime branch would close it
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- The package requires Laravel `^12.22 || ^13.0`. It is the constraint `0.2.0` shipped, and no generated rule needs
  12.22 now that no rule uses `integer:strict`. CI tests Laravel 12 from 12.55, the lowest its testbench allows, so
  nothing below it is tested.

## Alternatives turned down

- **Keeping `integer:strict`.** It is exact, and it refuses `1.0` and `1e2` with a finding. It was the decision of ADR
  0007, and it is stricter than the contract for every JSON client that writes a whole number as a float, such as `1.0`
  for a computed value.
- **Laravel's `integer` beside `numeric:strict`.** It reads well and needs no class of the package's, and it was the
  first reading of this decision. It reads a float as text with 14 significant digits, so a float a hair off a whole
  number is a `200` where `main` answered `422`, with `dto()` truncating it, and a whole float of 1e14 or more is a
  `422`. Both would need a finding on nearly every JSON integer, for a rule that is neither as strict nor as loose as
  the contract.
- **Rewriting a float to an `int` in `validated()`.** Laravel's `validated()` returns what was sent, and the DTO is the
  typed path. A hook in `passedValidation()` would make `validated()` differ from every other request.
- **`distinct:strict` for numbers as well,** and reporting `uniqueItems` for the float it lets through. It would leave
  `[1, 1.0]` accepted, which the contract calls a duplicate.

## Consequences

- **One more public class.** `WholeNumber` joins `NullOrAbsent` and `UndeclaredKeys` in what a generated file imports,
  so a request generated before this decision keeps `integer:strict` and still refuses `1.0` until `spec:build` runs
  again.
- **A float reaches the controller.** Code that reads an integer from `validated()` can get `31.0`, and the DTO gives an
  `int`. The CHANGELOG lists it as breaking.
- **A JSON integer is judged by its exact value**, within the range of a 64-bit integer: no finding is written for it,
  and a float of 2^63 or more is refused, as the loose `integer` refuses it, because the DTO could not cast it. One
  exception: an integer `enum` value of 1e14 or more sent as a float is refused, since `Rule::in` compares text and PHP
  writes that float as `1.0E+14`.
- **A body that is JSON and a form keeps the loose `integer`, with the gaps its finding names:** a JSON client's `"42"`
  and `true` get through, a float within 14 digits of a whole number (`2.9999999999999996`) passes as `3` and `dto()`
  casts it to `2`, and a whole float of 1e14 or more is refused. A runtime branch
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)) would close the first two, and
  [#196](https://github.com/Gcob/lara-spec-first/issues/196) tracks the integer's.
- **A unique list refuses two equal numbers it used to accept**: `[1, 1.0]`, `[10, 1e1]` and `[0, -0.0]` in JSON, `1`
  and `+1` or `1` and `1.0` in a form. The CHANGELOG lists it as breaking.
- **A unique list of numbers is not exact at float precision (2^53).** PHP compares an integer with a float, or two
  decimal strings, as two floats, so two numbers it rounds the same way count as one: `9007199254740993` and
  `9007199254740992.0` in JSON, `0.1` and `0.10000000000000000001` in a form. The guide states it, a test pins it, and
  the generated file carries no finding, since nothing in the contract names the case.
- **The Laravel constraint is unchanged, and its floor is untested.**
  [#188](https://github.com/Gcob/lara-spec-first/issues/188) settles it: test the declared floor in CI, or raise it to
  what CI installs.
- **Earlier records keep their examples.** ADRs [0003](./0003-validate-each-value-of-a-map.md) and
  [0014](./0014-judge-undeclared-keys-by-name.md) show a map value as `integer:strict`, and
  [ADR 0006](./0006-hold-rules-to-the-contract.md) lists the integral float among the gaps. They are dated records, and
  [the guide](../../guide/code-generation/request-validation.md#a-json-body-keeps-its-types) holds the rule today.
