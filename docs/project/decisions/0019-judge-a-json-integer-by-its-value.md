---
title: "ADR 0019: A JSON client's integer is judged by its exact value, in a body that is JSON and a form too"
audience: Users and contributors
covers: >
    The decision that a JSON integer is judged by the package's own `WholeNumber` rule in a body that is JSON and
    nothing else and in a body that is JSON and a form at once, where the rule also accepts the text a form carries
    (`acceptsText`), what a JSON client still gets through there (`"42"`), what of ADR 0016 still holds (a JSON body's
    scalars, `numeric` beside the rule, `validated()` and `dto()`, a unique list, the 2^53 limit, the enum exception),
    and the alternatives turned down.
read_before: >
    Asking why a JSON client's `2.9999999999999996` or `true` is a `422` for an integer of a body that is JSON and a
    form, why a form client's `42` still passes there, why a generated request writes `new WholeNumber(acceptsText:
    true)`, or changing the rule an integer gets.
tags: [decisions, code-generation, compatibility]
---

# ADR 0019: A JSON client's integer is judged by its exact value, in a body that is JSON and a form too

> **In brief**
>
> - An integer of a body that is JSON and a form at once is `WholeNumber(acceptsText: true)` beside `numeric`, where it
>   was Laravel's `integer`. A JSON client's number is judged by its exact value, as in a body that is JSON and nothing
>   else: `2.9999999999999996` and `true` are refused, and `1e14` passes.
> - `acceptsText` lets a string through when Laravel's own `integer` passes it, so a form client's `42` and `+42` pass
>   as before and `1.0` does not. The value's PHP type tells a form's text from a JSON number, which is why the contract
>   can be kept here.
> - What still gets through is a JSON client's `"42"`: one rule set cannot read a form's text and refuse a string in
>   JSON. The generated file says so in a finding, and a runtime branch would close it
>   ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
> - This replaces [ADR 0016](./0016-keep-json-scalars-typed.md), which kept Laravel's `integer` for that body. The rest
>   of its decision holds, and is restated here.
> - Turned down: the finding alone, a second class, Laravel's `integer` beside a float check, and the runtime branch.

**Status:** Accepted · **Card:** [#196](https://github.com/Gcob/lara-spec-first/issues/196) · **Pull request:**
[#220](https://github.com/Gcob/lara-spec-first/pull/220)

## Context

[ADR 0016](./0016-keep-json-scalars-typed.md) gave a JSON integer `WholeNumber`, a rule of the package's that passes an
`int` and a `float` whose value is exactly whole, because Laravel's own rules cannot be exact: `integer:strict` refuses
`1.0`, and the loose `integer` reads a float as text with 14 significant digits. It left one body out. An operation that
declares JSON and a form at once has one `rules()` array for both clients, and the form's values all arrive as text, so
that body kept the loose `integer` and a finding per field.

The finding named three gaps, for a JSON client of such an integer. `"42"` and `true` passed, because `integer` reads
text and the boolean `true` as `1`. A float within 14 digits of a whole number passed as a whole one:
`2.9999999999999996` (what JavaScript gives for `0.3 / 0.1`) became `3`, and `dto()` cast it to `2`, which is a value
the client never sent. And a whole float of 1e14 or more was refused, though the contract accepts it, since PHP writes
it with an exponent.

Two of the three were not a limit of one rule set. A form never sends a PHP `float` or a `bool`: the request reads its
fields as strings, and a JSON client's numbers arrive typed. So a rule can accept the text a form carries and still
judge a number by its value. Only `"42"` cannot be told apart, since a form's `42` and a JSON client's `"42"` are both
the string `42`. The decision about this body was a statement of a gap, where the contract could be kept.

## Decision

**An integer of a body that is JSON and a form at once is `new WholeNumber(acceptsText: true)`, with `numeric` beside
it,** at every level (`a.b`, `items.*`, a map's values, a name judged by shape). `WholeNumber` judges an `int` or a
`float` exactly as in a body that is JSON and nothing else: a whole float passes inside the range of a 64-bit integer,
and a fraction, a float a hair off a whole number, `true`, `null` and `[]` are refused. With `acceptsText`, a string
passes when `filter_var($value, FILTER_VALIDATE_INT)` passes it, which is Laravel's own `integer`, so a form client's
text is judged exactly as before: `42`, `+42` and `-0` pass, and `1.0`, `1e2` and `042` do not. It is a parameter of the
one class, and its default is the rule of a JSON-only body, which refuses every string.

**A JSON-only body keeps `new WholeNumber`, and a query parameter and a form or multipart field keep Laravel's
`integer`.** Every value the last two carry is text, and `integer` is exact for text. Only the body that is JSON and a
form changes.

**`numeric` goes beside it and `WholeNumber` comes first, with no `bail`,** as in a JSON-only body. Laravel finds the
number `min`, `max`, `gt` and `lt` compare by the name of a rule, and an object has none. So a value that is no number
at all (`"abc"`, `true`, `[]`, and the `null` an empty form field becomes) reads "must be a number" beside "must be an
integer", from a form client too.

**The finding stays, narrowed to what still gets through.** A JSON client's `"42"` passes there, as the contract refuses
it. The generated file says so in one finding per field, counted in the build warning, never listed as `stricter` and
never `unenforced`, so `spec:doctor` stays silent. The number's and the boolean's findings are as they were: they keep
the loose rules.

**`validated()` keeps what the client sent, and `dto()` casts it.** A JSON client's `31.0` stays a float in
`validated()` and is an `int` in the DTO, and `1e14` now reaches the DTO and casts exactly. A form client's text stays
text in `validated()`.

**What of [ADR 0016](./0016-keep-json-scalars-typed.md) still holds, restated here:**

- A JSON body's integer is `WholeNumber` and its number and boolean are `numeric:strict` and `boolean:strict`, at every
  level of a body that is JSON and nothing else. `WholeNumber` is public API, imported by a generated request, with its
  name frozen like `NullOrAbsent` and `UndeclaredKeys`.
- A query parameter and a form or multipart field keep the loose `integer` and `numeric`, and a text boolean.
- A form client of an operation that declares only JSON gets the JSON rules.
- A list with `uniqueItems` compares integers and numbers by value (`distinct`), and keeps `distinct:strict` for strings
  and booleans. A form's boolean has two spellings, `"true"` and `"1"`, which `distinct:strict` calls two elements: a
  gap left open ([#197](https://github.com/Gcob/lara-spec-first/issues/197)). So `[1, 1.0]`, `[10, 1e1]` and `[0, -0.0]`
  in JSON, and `1` and `+1` in a form, are duplicates. This came with ADR 0016, and `uniquenessRule()` is unchanged
  here. A form's `1.0` is not an integer, so `1` and `1.0` cannot both be valid there.
- A unique list of numbers is not exact at float precision (2^53). PHP compares an integer with a float, or two decimal
  strings, as two floats, so `9007199254740993` and `9007199254740992.0` in JSON, or `0.1` and `0.10000000000000000001`
  in a form, count as one. The guide states it, a test pins it, and the generated file carries no finding, since nothing
  in the contract names the case.
- A JSON client's `31.0` reaches `validated()` as a float and the DTO gives an `int`, which the loose `integer` of this
  body already allowed.
- A JSON integer is judged within the range of a 64-bit integer, and an integer `enum` value of 1e14 or more sent as a
  float is refused, since `Rule::in` compares text and PHP writes that float as `1.0E+14`.
- The Laravel constraint is no longer part of this decision: no rule described here needs a Laravel version, and
  [ADR 0018](./0018-test-the-declared-laravel-floor.md) holds the floor.

## Alternatives turned down

- **Keeping the loose `integer` and stating the gap.** It was ADR 0016's reading, and it is looser than the contract
  where the contract can be kept: the PHP type of the value tells a form's text from a JSON number, so the near-whole
  float and `true` do not need a finding.
- **A second class for the body that is JSON and a form** (`IntegerText`). It would judge the same value in a different
  way for one parameter, and cost a second import in a generated file and every type union that names `WholeNumber` (the
  rule walk, the rule set, the builder, the tests' helpers). Laravel has one `integer` with a `strict` mode, not two
  rules, and one class with a mode follows it. `WholeNumber` had not shipped when this was decided (0.2.0 did not have
  it), and the default is the behavior it has today.
- **Laravel's `integer` beside a float check.** A check on the float keeps the refusal of a whole float of 1e14 or more,
  which `integer` reads as `1.0E+14`, and it still lets `true` through. It also needs a rule written for that one body,
  where `WholeNumber` already holds the exact comparison.
- **A runtime branch** that picks the JSON rules or the form rules by the request's content type
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)). It would refuse `"42"` as well, but `rules()` would
  become two arrays, which is a bigger change than this one, and it does not wait on this decision: if it lands,
  `acceptsText` has no user and can go before 1.0.

## Consequences

- **A JSON client's `true` and near-whole float get a `422` where they got a `200`, and `1e14` gets a `200`.** The
  CHANGELOG lists it as breaking. A project's `validation.custom.<field>.integer` message no longer applies to this
  body, and a request generated before keeps `integer` until `spec:build` runs again.
- **A non-number reads two messages.** "Must be a number" is added beside "must be an integer" for `"abc"`, `true` and
  an empty form field, for a form client as well. A `true` can also read the `min` or `max` message, which compares the
  length of `"1"` since there is no number: a `minimum` above 1 or a `maximum` below 1 adds it, and `min:0` or `min:1`
  does not.
- **A JSON client's `"42"` still passes for an integer of a body that is JSON and a form,** as a number's `"1.5"` and a
  boolean's `"1"` and `1` do. The finding says so, and [#133](https://github.com/Gcob/lara-spec-first/issues/133) is the
  way to close it.
- **Earlier records keep their examples.** ADRs [0003](./0003-validate-each-value-of-a-map.md) and
  [0014](./0014-judge-undeclared-keys-by-name.md) show a map value as `integer:strict`,
  [ADR 0006](./0006-hold-rules-to-the-contract.md) lists the integral float among the gaps, and
  [ADR 0016](./0016-keep-json-scalars-typed.md) shows the loose `integer` for a body that is JSON and a form. They are
  dated records, and [the guide](../../guide/code-generation/request-validation.md#a-json-body-keeps-its-types) holds
  the rule today.
