---
title: "ADR 0006: A generated rule follows the contract in both directions, and each departure is stated"
audience: Users and contributors
covers: >
    The decision that a generated rule accepts what the contract accepts and refuses what it refuses, with named
    departures that are stated where the keyword is mapped or tracked by a card, that a stricter rule needs a finding,
    and the alternatives turned down.
read_before: >
    Reviewing a generated rule for being too loose or too strict, asking why a request refuses a payload the contract
    allows, or adding a keyword whose Laravel rule does not match it exactly.
tags: [decisions, code-generation, openapi, compatibility]
---

# ADR 0006: A generated rule follows the contract in both directions, and each departure is stated

> **In brief**
>
> - A keyword becomes the Laravel rule that means the same thing, or a report. A generated rule never accepts what the
>   contract refuses, except a named departure that is stated where the keyword is mapped or tracked by a card.
> - A rule is never stricter than the contract either, unless matching it exactly would be far too complex in Laravel,
>   and then a finding in the generated file says so.
> - The known departures are written down as examples, each with where it is stated or tracked. None of them is a
>   complete list, because the next one found would make it false.
> - Turned down: keeping Laravel's own rule and adding a finding, which answered `422` to every client that followed the
>   contract.

**Status:** Accepted · **Card:** [#35](https://github.com/Gcob/lara-spec-first/issues/35) · **Pull request:**
[#105](https://github.com/Gcob/lara-spec-first/pull/105)

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
generated file, and `spec:doctor` for what the builder also records as data. Nothing is dropped in silence.

**A generated rule never accepts what the contract refuses, except a named departure.** A departure is stated where the
keyword is mapped (the topic files of `docs/guide/`, or an ADR) or tracked by a card. Laravel's limits alone never
justify one: Laravel has a rule that fits several of the examples below, and each was left as it is for a reason of its
own.

**A generated rule never refuses what the contract accepts, unless matching it exactly would be far too complex in
Laravel, and then a finding says so.** A rule that is stricter in silence is a defect, and a stricter rule with its
finding is a decision somebody can read. Three kinds of finding exist in the generated file, and the build counts them
apart. A finding about a constraint read and left unenforced is a gap, and `spec:build` counts it in its warning that
requests read a constraint they do not enforce. A finding that says the request is stricter than the contract, and one
that loses no constraint, are not counted: the warning would otherwise fire on nearly every JSON request.

**The known departures are examples, not a list.** The ADR does not say how many there are, since it cannot be edited
after it merges and the next one found would make a count false.

The request is stricter, with a finding:

- **A file part's `mimetypes`** refuses a file whose name ends in a PHP extension (`php`, `phtml`, `phar`), whatever its
  type. Laravel's rule does it as a security default that no rule turns off, and matching the contract there would mean
  overriding it from a generated class.
- **A recursive schema is cut at a depth.** A payload that goes deeper answers `422`, though the contract allows it. The
  schema's `x-max-depth` sets the depth, and the finding names it.
- **A body with `properties` and no `type`** is read as an object, so a JSON root that is not one (the string `"Ada"` is
  valid against that schema) is refused. A root that may be an object or anything else has no field for a rule to sit
  on.
- **A JSON integer written with a fraction or an exponent** (`1.0`, `1e2`) is refused by `integer:strict`, though JSON
  Schema 2020-12 reads it as an integer. It is refused, with a finding. Laravel's `integer` beside `numeric:strict`
  would accept it, and the package keeps the single rule. The reasoning behind the strict rule is in
  [ADR 0007](./0007-keep-json-scalars-strict.md).
- **In a body that is JSON and a form at once, a boolean keeps `boolean`**, so a form client's `"true"` is refused. The
  finding on the field names that and what a JSON client gets through. One rule set cannot read both, and this finding
  counts as a gap in the build's warning.
- **A non-nullable string refuses `""` because of `ConvertEmptyStringsToNull`**, Laravel's default middleware, which
  turns `""` into `null` before any rule runs. That is the framework's and not a rule of the package, so it is stated
  once in the guide and not reported per field.

The request is looser, today, for example:

- **An integer, number or boolean of a body that is JSON and a form** keeps the loose rule, so a JSON client's `"42"`
  passes: one rule set cannot read a form's text and still refuse a string in JSON. A finding names the field, and a
  runtime branch would close it ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- **A boolean read as text accepts `"1"` and `"0"`**, which the contract does not write for a boolean. It is kept
  because query and form clients send them. A finding on the field says it accepts the two more.
- **A blank string in a project that removed `ConvertEmptyStringsToNull`** can pass a property the contract refuses.
  Laravel skips every rule that is not implicit on an empty string, and only an implicit rule refuses it. The example is
  an optional integer sent as `""`: it passes, and a DTO that reads it with `(int)` gets `0`. The guide documents this
  case, and [#157](https://github.com/Gcob/lara-spec-first/issues/157) is the card for it. Under Laravel's default
  middleware stack it does not happen.
- **`pattern`'s `.`** matches `\r` and U+2028 under PCRE, which ECMA-262's does not, so `"a\rb"` passes `^a.b$`. It is a
  stated limit of [the support matrix](../../guide/openapi-support.md#strings-and-numbers), with no finding.
- **`format: email` against Laravel's `email`**, which follows RFC 5322, accepts a comment such as
  `test(comment)@example.com`, which JSON Schema's `email` (RFC 5321) refuses.
  [#167](https://github.com/Gcob/lara-spec-first/issues/167) tracks it, and this ADR does not decide the fix.
- **A recursion through a schema with no name, or through a field that is two markers at once,** validates nothing below
  the field. A finding in the generated file says so, and `x-max-depth` does not lift it, since there is no schema to
  write it on. [ADR 0009](./0009-cut-recursion-at-a-stated-depth.md) states it.
- **A required plain query name beside the names PHP nests under it:** `?page[size]=3` satisfies a required `page`,
  since the array stands in for it. [ADR 0002](./0002-judge-plain-query-names-by-shape.md) states it, with no finding
  and no card.
- **A 3.1 string's `contentType` default:** `spec:doctor` does not report a `contentType` that 3.1.1 would flag on a 3.1
  string until the schema carries `contentEncoding`. [ADR 0005](./0005-read-default-content-type-by-latest-patch.md)
  states it, and [#146](https://github.com/Gcob/lara-spec-first/issues/146) tracks it.

## Alternatives turned down

- **Keep Laravel's own rule and add a finding**, as the first `boolean` did. A finding tells the reader why the rule
  refused, and the client still got a `422` for a request the contract allows. A finding is for what the package cannot
  do, not for a refusal it chose.
- **Never stricter, with no exception.** Some constraints have no exact Laravel rule at a cost worth paying. A recursion
  with no depth cannot be unrolled forever, so the only way not to refuse a payload past the cut is to drop every rule
  below it. A root that may be an object or anything else has no field for a rule, so to refuse nothing the rules of its
  properties would have to go, and an object would pass unchecked. Laravel's refusal of a PHP-named upload cannot be
  turned off by a rule, so the ways around it are to drop `mimetypes` or to override a security default from a generated
  class. The first is looser in each of these cases, and the exception keeps the rule and says what it costs.
- **Never looser, with no exception, at any cost.** Said case by case, never as one claim about all of them:
    - **Weighed and turned down for their cost.** The mixed body: a runtime branch would make `rules()` two arrays
      ([#133](https://github.com/Gcob/lara-spec-first/issues/133)). The text boolean's `"1"` and `"0"`: an exact
      `Rule::in(['true', 'false'])` exists, and it was not taken: a query string and a form already send `1` and `0`,
      and Laravel's own `boolean` takes them. `pattern`'s `.`: reporting every pattern that uses it would leave most
      patterns unenforced, to guard against input that rarely carries `\r` or U+2028.
    - **Stated or tracked.** A recursion that validates nothing below its field
      ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)); the blank string, found to be wider than the case first
      documented ([#157](https://github.com/Gcob/lara-spec-first/issues/157)); `format: email`, where no Laravel rule
      matches RFC 5321's mailbox ([#167](https://github.com/Gcob/lara-spec-first/issues/167)); the required plain query
      name ([ADR 0002](./0002-judge-plain-query-names-by-shape.md)); and the 3.1 string's `contentType`
      ([ADR 0005](./0005-read-default-content-type-by-latest-patch.md),
      [#146](https://github.com/Gcob/lara-spec-first/issues/146)). They were not turned down: they are named so that
      nobody reads "never looser" as a claim the code does not keep.

## Consequences

- **A contributor reviewing a rule checks both directions**, as the review priorities of `AGENTS.md` say: a rule looser
  than the contract is a defect unless it is a named departure, and a rule stricter than it is a defect without its
  finding.
- **The stricter cases and the known looser ones are named as examples**, each with where it is stated or tracked. A
  departure found later goes where its keyword is mapped, or gets a card, and needs no change to this record.
- **A stricter finding and a finding that loses no constraint are not counted** by `spec:build`'s warning, and a
  generated file with only those keeps its line saying that nothing was read and left unenforced.
- **`spec:doctor` stays silent on the gaps that are prose only**, such as a JSON client's `"42"` in a body that is JSON
  and a form, so a pipeline does not fail over a remark.
- **What the decision leaves open** is [#133](https://github.com/Gcob/lara-spec-first/issues/133),
  [#146](https://github.com/Gcob/lara-spec-first/issues/146), [#157](https://github.com/Gcob/lara-spec-first/issues/157)
  and [#167](https://github.com/Gcob/lara-spec-first/issues/167).
