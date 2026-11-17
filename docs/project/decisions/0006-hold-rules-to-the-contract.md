---
title: "ADR 0006: A generated rule is never looser than the contract, and a stricter one carries a finding"
audience: Users and contributors
covers: >
    The decision that a generated rule never accepts what the contract refuses, that a rule stricter than the contract
    is allowed only when matching it exactly would be far too complex in Laravel and then carries a finding, the places
    where the code falls short of the rule today, and the alternatives turned down.
read_before: >
    Reviewing a generated rule for being too loose or too strict, asking why a request refuses a payload the contract
    allows, or adding a keyword whose Laravel rule does not match it exactly.
tags: [decisions, code-generation, openapi, compatibility]
---

# ADR 0006: A generated rule is never looser than the contract, and a stricter one carries a finding

> **In brief**
>
> - A keyword becomes the Laravel rule that means the same thing, or a report. A generated rule is never looser than the
>   contract: it never accepts a payload the contract refuses.
> - A rule is never stricter than the contract either, unless matching it exactly would be far too complex in Laravel,
>   and then a finding in the generated file says so.
> - The code falls short of the first rule in places. Each is listed here as a gap with its card or its stated limit,
>   and none is an exception: a new looser rule is a defect, and the list is never a complete one.
> - Turned down: keeping Laravel's own rule and adding a finding, which answered `422` to every client that followed the
>   contract, and letting a looser rule through when a card names it.

**Status:** Accepted · **Cards:** [#35](https://github.com/Gcob/lara-spec-first/issues/35) (the rule) and
[#162](https://github.com/Gcob/lara-spec-first/issues/162) (the list of gaps) · **Pull requests:**
[#105](https://github.com/Gcob/lara-spec-first/pull/105) (the rule) and
[#172](https://github.com/Gcob/lara-spec-first/pull/172) (the list of gaps)

## Context

A rule set is derived from a contract, and a rule can be wrong in two directions. A rule that accepts a payload the
contract refuses is a wrong answer, where a missing rule reported in a finding is only a missing one, and only the
second is recoverable. A rule that refuses a payload the contract accepts gives every generated client a `422` for
something it was allowed to send, and nobody reading the contract can see why.

The second direction was the one the package got wrong first. Laravel's `boolean` refuses the string `"true"`, and
OpenAPI's default `form` style writes a boolean in a query string as `?notify=true`. The first generated rule for a
boolean was Laravel's own, so every client that followed the contract was refused. The documentation of the time forbade
only a looser rule and called that `422` the price of not using one, and a test pinned it. The maintainer reversed it:
the principle runs both ways, and a rule that refuses what the contract accepts is not a safe default.

## Decision

**A keyword becomes the Laravel rule that means the same thing, or it becomes a report.** A report is a finding in the
generated file, and `spec:doctor` for what the builder also records as data. Nothing the build reads is dropped in
silence. A keyword the build does not read, such as a 3.0 keyword written beside a `$ref`, leaves no trace
([ADR 0008](./0008-honor-keywords-beside-a-ref.md)).

**A generated rule is never looser than the contract.** It never accepts a payload the contract refuses. The rule has no
exception, and a review treats a new looser rule as a defect, with or without a card open for it.

**A generated rule is never stricter than the contract, unless matching it exactly would be far too complex in Laravel,
and then a finding says so.** A rule that is stricter in silence is a defect, and a stricter rule with its finding is a
decision somebody can read. The build counts a gap apart from a finding that says the request is stricter and from a
finding that loses no constraint. A gap is a constraint read and left unenforced, and `spec:build` counts it in its
warning that requests read a constraint they do not enforce. The other two are not counted: the warning would otherwise
fire on nearly every JSON request.

The request is stricter, with a finding, because matching the contract is far too complex in Laravel:

- **A file part's `mimetypes`** refuses a file whose name ends in a PHP extension (`php`, `phtml`, `phar`), whatever its
  type. Laravel's rule does it as a security default that no rule turns off, and matching the contract there would mean
  overriding it from a generated class.
- **A recursive schema is cut at a depth.** A payload that goes deeper answers `422`, though the contract allows it. The
  schema's `x-max-depth` sets the depth, and the finding names it.
- **A body with `properties` and no `type`** is read as an object, so a JSON root that is not one (the string `"Ada"` is
  valid against that schema) is refused. A root that may be an object or anything else has no field for a rule to sit
  on.
- **In a body that is JSON and a form at once, a boolean keeps `boolean`**, so a form client's `"true"` is refused. The
  finding on the field names that and what a JSON client gets through. One rule set cannot read both, and this finding
  counts as a gap in the build's warning.

A non-nullable string also refuses `""`, because Laravel's default `ConvertEmptyStringsToNull` middleware turns it into
`null` before any rule runs. That is the framework's and not a rule of the package, so it is stated once in the guide
and not reported per field.

**Where the code falls short of these rules today, the gap is listed, never excused.** Each has its card or its stated
limit. The list is what is known, not a complete one: it says no count, since this record cannot be edited after it
merges and the next gap found would make a count false.

The request is looser than the contract:

- **An integer, number or boolean of a body that is JSON and a form** keeps the loose rule, so a JSON client's `"42"`
  passes: one rule set cannot read a form's text and still refuse a string in JSON. A finding names the field. A runtime
  branch would close it, at the cost of `rules()` being two arrays
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- **A boolean read as text accepts `"1"` and `"0"`**, which the contract does not write for a boolean. It is kept
  because query and form clients send them, and a finding on the field says it accepts the two more. An exact
  `Rule::in(['true', 'false'])` exists, and it was not taken.
- **A blank string in a project that removed `ConvertEmptyStringsToNull`** can pass a property the contract refuses.
  Laravel skips every rule that is not implicit on an empty string, and only an implicit rule refuses it. The example is
  an optional integer sent as `""`: it passes, and a DTO that reads it with `(int)` gets `0`. The guide documents this
  case, and [#157](https://github.com/Gcob/lara-spec-first/issues/157) is the card for it. Under Laravel's default
  middleware stack it does not happen.
- **`pattern`'s `.`** matches `\r` and U+2028 under PCRE, which ECMA-262's does not, so `"a\rb"` passes `^a.b$`. It is a
  stated limit of [the support matrix](../../guide/openapi-support.md#strings-and-numbers), with no finding: reporting
  every pattern that uses `.` would leave most patterns unenforced, to guard against input that rarely carries `\r` or
  U+2028.
- **`format: email` against Laravel's `email`**, which follows RFC 5322, accepts a comment such as
  `test(comment)@example.com`, which JSON Schema's `email` (RFC 5321) refuses.
  [#167](https://github.com/Gcob/lara-spec-first/issues/167) tracks it, and this ADR does not decide the fix.
- **A recursion through a schema with no name, or through a field that is two markers at once,** validates nothing below
  the field. A finding in the generated file says so, and `x-max-depth` does not lift it, since there is no schema to
  write it on. [ADR 0009](./0009-cut-recursion-at-a-stated-depth.md) states it.
- **A required plain query name beside the names PHP nests under it:** `?page[size]=3` satisfies a required `page`,
  since the array stands in for it. [ADR 0002](./0002-judge-plain-query-names-by-shape.md) states it, with no finding
  and no card.

The request is stricter, without the reason that would allow it:

- **A JSON integer written with a fraction or an exponent** (`1.0`, `1e2`) is refused by `integer:strict`, though JSON
  Schema 2020-12 reads it as an integer. A finding says so, once per request with an integer field. Laravel's `integer`
  beside `numeric:strict` would accept it, and the package refuses it today.
  [#173](https://github.com/Gcob/lara-spec-first/issues/173) tracks the choice, and the strict rule itself is
  [ADR 0007](./0007-keep-json-scalars-strict.md)'s.

One more is the doctor's and not a request's: `spec:doctor` does not report a `contentType` that 3.1.1 would flag on a
3.1 string until the schema carries `contentEncoding`, so the doctor is looser than the contract there and the request
is not. [ADR 0005](./0005-read-default-content-type-by-latest-patch.md) states it, and
[#146](https://github.com/Gcob/lara-spec-first/issues/146) tracks it.

## Alternatives turned down

- **Keep Laravel's own rule and add a finding**, as the first `boolean` did. A finding says what a rule does, and it
  does not make the refusal right: the client still got a `422` for a request the contract allows, and a rule that
  matched the contract exactly existed (`Rule::in`).
- **Never stricter, with no exception.** Some constraints have no exact Laravel rule at a cost worth paying. Not
  refusing a payload past a recursion's cut would cost a rule of the package's own that validates recursively. A root
  that may be an object or anything else has no field for a rule to sit on, so matching it would cost a check of the
  package's own. Laravel's refusal of a PHP-named upload cannot be turned off by a rule, so matching the contract would
  cost overriding a security default from a generated class. The exception keeps Laravel's rule and says what it costs.
- **Let a looser rule through when the docs or a card name it.** A review would then accept any new looser rule once a
  card is open for it, and the contract would stop being the bar. The gaps above are not exceptions, and each one is the
  code falling short of a rule that stays.
- **State the rule and say nothing of the gaps.** The rule would then be false of the code, and a reader who found
  `"42"` passing for an integer would take the code for the bug and the page for the truth, or the other way round.

## Consequences

- **A contributor reviewing a rule checks both directions**, as the review priorities of `AGENTS.md` say: a rule looser
  than the contract is a defect, and one stricter than it is a defect without its finding. A gap found later gets a card
  or a stated limit where its keyword is mapped, and is added to the guide.
- **The known gaps are named, each with its card or its stated limit.** They are examples and not a list, and this
  record needs no change when one more is found.
- **A stricter finding and a finding that loses no constraint are not counted** by `spec:build`'s warning, and a
  generated file with only those keeps its line saying that nothing was read and left unenforced.
- **`spec:doctor` stays silent on the gaps that are prose only**, such as a JSON client's `"42"` in a body that is JSON
  and a form, so a pipeline does not fail over a remark.
- **What the decision leaves open** is [#133](https://github.com/Gcob/lara-spec-first/issues/133),
  [#146](https://github.com/Gcob/lara-spec-first/issues/146),
  [#157](https://github.com/Gcob/lara-spec-first/issues/157), [#167](https://github.com/Gcob/lara-spec-first/issues/167)
  and [#173](https://github.com/Gcob/lara-spec-first/issues/173).
