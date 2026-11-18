---
title: "ADR 0016: A JSON body's scalars keep their types, and a whole number written as a float is an integer"
audience: Users and contributors
covers: >
    The decision that a JSON integer is Laravel's `integer` beside `numeric:strict`, so it accepts a whole number
    written as a float (`1.0`, `1e2`) below 1e14 in magnitude and still refuses `"42"` and `true`, that a unique list of
    numbers is compared by value, what of ADR 0007 still holds (strict numbers and booleans in a JSON body, loose text
    fields, a body that is JSON and a form, the Laravel constraint), and the alternatives turned down.
read_before: >
    Asking why a JSON client's `1.0` is a `200` for an integer and `"42"` is not, why `1e14` is still refused, why a
    unique list calls `1` and `1.0` one element, or changing the rule a JSON integer gets.
tags: [decisions, code-generation, compatibility, laravel, versions]
---

# ADR 0016: A JSON body's scalars keep their types, and a whole number written as a float is an integer

> **In brief**
>
> - A JSON integer is `integer` beside `numeric:strict`: `1.0` and `1e2` pass, as they do for JSON Schema 2020-12, and
>   `"42"`, `true` and `1.5` are still refused.
> - A whole float of 1e14 or more in magnitude stays refused, because PHP writes it with an exponent under its default
>   `precision` and Laravel's `integer` refuses that text. A finding says so, only where a field can hold such a value.
> - A list with `uniqueItems` compares numbers by value (`distinct`), so `[1, 1.0]` is a duplicate. Past float precision
>   the comparison is not exact, and that limit is stated, not reported.
> - The rest of ADR 0007 holds: strict numbers and booleans in a JSON body, loose rules for text, the body that is JSON
>   and a form, the Laravel constraint.
> - Turned down: keeping `integer:strict`, and a rule of the package's own for a float of 1e14 or more.

**Status:** Accepted · **Cards:** [#35](https://github.com/Gcob/lara-spec-first/issues/35),
[#107](https://github.com/Gcob/lara-spec-first/issues/107) and
[#173](https://github.com/Gcob/lara-spec-first/issues/173) · **Pull requests:**
[#105](https://github.com/Gcob/lara-spec-first/pull/105), [#135](https://github.com/Gcob/lara-spec-first/pull/135) and
its pull request

## Context

[ADR 0007](./0007-keep-json-scalars-strict.md) gave a JSON integer `integer:strict`, which is `is_int()` on the decoded
value. That refused `"42"` and `true`, as the contract does, and it also refused `1.0` and `1e2`, which JSON Schema
2020-12 reads as integers. The decision took the refusal as the price of the strict rule, and said so in a finding.

The premise was that only a rule of the package's own could accept `1.0` and still refuse `"42"`. It was false:
Laravel's `integer` accepts a whole number written as a float, and `numeric:strict` refuses what is not a JSON number,
so `integer` beside `numeric:strict` does both. Measured on the vendored validator, `1.0`, `1e2`, `-0.0` and
`99999999999999.0` pass, `"42"` and `true` are refused by `numeric:strict`, and `1.5` is refused by `integer`.

One thing does not pass. `integer` is PHP's `FILTER_VALIDATE_INT` on the value read as text, and under PHP's default
`precision` (14) a float of 1e14 or more is written with an exponent (`1.0E+14`), which that filter refuses. So the
request is still stricter than the contract for a whole float of that size.

Moving to the pair also changes what a unique list does. `distinct:strict` called `1` and `1.0` two elements, so once a
JSON integer accepts `1.0`, a list with `uniqueItems` would let `[1, 1.0]` through, which the contract calls a
duplicate. The same gap already existed for a list of numbers (`[1, 1.0]`, `[10, 1e1]`, `[0, -0.0]`) and for a form list
(`1` and `+1`, `1` and `1.0`).

## Decision

**A JSON integer is `integer` beside `numeric:strict`, at every level** (`a.b`, `items.*`, a map's values) of a body
that is JSON and nothing else. `integer` comes first, so a value that is neither a number nor an integer (`"abc"`,
`false`, `[]`) gets "must be an integer" first, and `"42"` and `true` read "must be a number". No `bail`: that is
Laravel's default, and it would also hide a `min` or `max` message. `min`, `max`, `gt`, `lt` and `multiple_of` beside
the pair still compare as numbers, because Laravel finds an integer or a number by the rule's name.

**A whole float of 1e14 or more in magnitude stays refused, and the finding says why.** It is one finding per request,
written only when an integer field can hold such a value: an integer with `format: int32`, with bounds under 1e14 in
magnitude, or with an `enum` or a `const` of values under it gets none. A body that is JSON and a form gets it too, in
its own wording, because its loose `integer` refuses a JSON client's `1e14` as well. It is a finding that says the
request is stricter than the contract, so it stays out of the build warning.

**`validated()` keeps the float as sent, and `dto()` casts it to an `int`.** That is how a query parameter's text
already works: `validated()` returns what the client wrote, and the typed path is the DTO.

**A list with `uniqueItems` compares integers and numbers by value.** Its element rule is Laravel's `distinct` in a JSON
body and in a form, and `distinct:strict` stays for strings and booleans, which have no second spelling (`"1"` and
`"01"` are two strings). An array query parameter has no element rule, so nothing changes for it.

**The rest of [ADR 0007](./0007-keep-json-scalars-strict.md) holds, and is restated here:**

- A JSON body's numbers and booleans are `numeric:strict` and `boolean:strict`, at every level.
- A query parameter and a form or multipart field keep the loose `integer` and `numeric`, and a text boolean, because
  every value they carry is text.
- A form client of an operation that declares only JSON gets the JSON rules.
- A body that is JSON and a form at once keeps the loose rules, so a JSON client's `"42"` and `true` get through an
  integer, and a finding per field says so. A runtime branch would close it
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- The package requires Laravel `^12.22 || ^13.0`. It is the constraint `0.2.0` shipped, and no generated rule needs
  12.22 now that no rule uses `integer:strict`. CI tests Laravel 12 from 12.55, the lowest its testbench allows, so
  nothing below it is tested.

## Alternatives turned down

- **Keeping `integer:strict`.** It is exact about `"42"` and `true`, and it refuses `1.0` and `1e2` with a finding. It
  was the decision of ADR 0007 on a premise that no longer holds, and it is stricter than the contract for every JSON
  client that writes a whole number as a float, such as `1.0` for a computed value.
- **A rule of the package's own for a whole float of 1e14 or more.** It would take a public class every generated file
  imports, for a client that writes a whole number of 15 digits or more with a fraction or an exponent. A JavaScript
  client writes `123456789012345` without either, and PHP decodes an integer. The refusal is a stated limit with its
  finding, and no card.
- **Rewriting a float to an `int` in `validated()`.** Laravel's `validated()` returns what was sent, and the DTO is the
  typed path. A hook in `passedValidation()` would make `validated()` differ from every other request.
- **`distinct:strict` for numbers as well,** and reporting `uniqueItems` for the float it lets through. It would leave
  `[1, 1.0]` accepted, which the contract calls a duplicate.

## Consequences

- **A float reaches the controller.** Code that reads an integer from `validated()` can get `31.0`, and the DTO gives an
  `int`. A client that matches the message for `"42"` sees "must be a number" where it read "must be an integer", and a
  value that is neither gets both messages. The CHANGELOG lists both as breaking.
- **A unique list refuses two equal numbers it used to accept**: `[1, 1.0]`, `[10, 1e1]` and `[0, -0.0]` in JSON, `1`
  and `+1` or `1` and `1.0` in a form. The CHANGELOG lists it as breaking.
- **A unique list of numbers is not exact at float precision (2^53).** PHP compares an integer with a float, or two
  decimal strings, as two floats, so two numbers it rounds the same way count as one: `9007199254740993` and
  `9007199254740992.0` in JSON, `0.1` and `0.10000000000000000001` in a form. The guide states it, a test pins it, and
  the generated file carries no finding, since nothing in the contract names the case. A JSON integer list cannot meet
  it: a float that large is refused by `integer` first.
- **A whole float of 1e14 or more is refused**, with its finding, and that is the one place a JSON integer is stricter
  than JSON Schema 2020-12 for a whole float.
- **The Laravel constraint is unchanged, and its floor is untested.**
  [#188](https://github.com/Gcob/lara-spec-first/issues/188) settles it: test the declared floor in CI, or raise it to
  what CI installs.
- **Earlier records keep their examples.** ADRs [0003](./0003-validate-each-value-of-a-map.md) and
  [0014](./0014-judge-undeclared-keys-by-name.md) show a map value as `integer:strict`, and
  [ADR 0006](./0006-hold-rules-to-the-contract.md) lists the integral float among the gaps. They are dated records, and
  [the guide](../../guide/code-generation/request-validation.md#a-json-body-keeps-its-types) holds the rule today.
