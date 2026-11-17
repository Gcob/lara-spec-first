---
title: "ADR 0007: A JSON body's integers, numbers and booleans are strict, and query and form fields stay loose"
audience: Users and contributors
covers: >
    The decision that an integer, a number and a boolean of a body that is JSON and nothing else get Laravel's `:strict`
    rules, that a query parameter and a form or multipart field keep the loose ones, how a body that is both is read,
    the Laravel floor the strict rules set, and the alternatives turned down.
read_before: >
    Asking why a JSON client's `"42"` is a `422` for an integer, why `?notify=true` passes for a boolean, changing the
    rule a scalar gets, or widening the range of Laravel the package supports.
tags: [decisions, code-generation, compatibility, laravel, versions]
---

# ADR 0007: A JSON body's integers, numbers and booleans are strict, and query and form fields stay loose

> **In brief**
>
> - A body that is JSON and nothing else gets `integer:strict`, `numeric:strict` and `boolean:strict` at every level:
>   JSON writes `42`, `1.5` and `true` already typed, and `"42"` is a string.
> - A query parameter and a form or multipart field keep the loose `integer` and `numeric`, and a text boolean, since
>   every value they carry is text.
> - A body that is JSON and a form at once keeps the loose rules, and a finding per field names what a JSON client gets
>   through. A runtime branch would close it.
> - `integer:strict` is silently ignored before Laravel 12.22, so the package requires `^12.22 || ^13.0`.
> - Turned down: Laravel's loose rules on a JSON body, which accept `"42"`, and rules of the package's own to keep
>   Laravel 12.0 to 12.21.

**Status:** Accepted · **Cards:** [#35](https://github.com/Gcob/lara-spec-first/issues/35) (the text boolean) and
[#107](https://github.com/Gcob/lara-spec-first/issues/107) · **Pull requests:**
[#105](https://github.com/Gcob/lara-spec-first/pull/105) and [#135](https://github.com/Gcob/lara-spec-first/pull/135)

## Context

Laravel's `integer`, `numeric` and `boolean` read what a form or a query string writes, which is text. `integer` accepts
`"42"`, `numeric` accepts `"1.5"`, and `boolean` accepts `1`, `0`, `"1"` and `"0"`. A JSON body carries types instead:
`42` is an integer, `"42"` is a string, and a contract that says `type: integer` refuses the second.

A JSON body got the same loose rules as everything else, so a JSON client's `"42"` passed an integer, which is a rule
accepting what the contract refuses. The text boolean had gone the other way first:
[ADR 0006](./0006-hold-rules-to-the-contract.md) tells how Laravel's `boolean` refused `?notify=true`, a request the
contract accepts. The two cases have one cause: a JSON body is typed, and a query string and a form are not.

Laravel has `:strict` forms of these rules, with one catch. `numeric:strict` and `boolean:strict` exist from 12.21, but
`integer` read no parameter until 12.22, so `integer:strict` on 12.21 or before is accepted and silently ignored, and an
integer would accept `"42"` with nothing to say so.

## Decision

**A body that is JSON and nothing else gets `integer:strict`, `numeric:strict` and `boolean:strict`, at every level**
(`a.b`, `items.*`). `integer:strict` is `is_int()` on the decoded value, `numeric:strict` refuses a string, and
`boolean:strict` accepts `true` and `false` and nothing else. `min`, `max`, `gt`, `lt` and `multiple_of` beside them
still compare as numbers, because Laravel finds an integer or a number by the rule's name, whatever follows the colon.

**A query parameter and a form or multipart field keep `integer` and `numeric`, and a boolean is
`Rule::in(['true', 'false', '1', '0'])`.** Every value they carry is text, so the strict forms would refuse everything.
`validated()` keeps the text as sent (`'2'` for `?limit=2`), and the DTO of a form or multipart body casts it.

**A form client of an operation that declares only JSON gets the JSON rules.** The strict rules follow the media types
the contract declares, not what the client sends: an HTML form posting `age=30` to such an operation is a `422`, since
the contract declares no form and that client is off-contract.

**A body that is JSON and a form at once keeps the loose rules.** One rule set cannot read the text a form carries and
still refuse a string in JSON, so an integer there accepts `"42"` and `true`, a number accepts `"1.5"`, and a boolean
accepts `"1"` and `1`, all of which the contract refuses. A finding per field says so. A rule that branched on the
request (`$this->isJson()`) would be exact for both, and [#133](https://github.com/Gcob/lara-spec-first/issues/133) is
the card for it.

**The package requires Laravel `^12.22 || ^13.0`.** `integer:strict` is the rule that sets the floor, and the test
matrix starts at 12.22 for that reason.

## Alternatives turned down

- **Laravel's loose `integer`, `numeric` and `boolean` on a JSON body.** They accept `"42"`, `"1.5"` and `1` for a
  boolean, which the contract refuses, and a rule accepting what the contract refuses is the direction
  [ADR 0006](./0006-hold-rules-to-the-contract.md) rules out.
- **Rules of the package's own** (a JSON integer, number and boolean class, like `NullOrAbsent`) to keep Laravel 12.0 to
  12.21. That is three public class names that every generated file would import and the package would then have to
  keep, in exchange for a range of patch releases.
- **A runtime branch for a body that is JSON and a form** (`$this->isJson() ? strict : loose`). It is exact for both,
  but `rules()` would stop being one array. It is not turned down for good: it is
  [#133](https://github.com/Gcob/lara-spec-first/issues/133), and it stays what this decision leaves open.
- **Laravel's `boolean` for a query parameter or a form field.** It refuses `"true"`, which is how OpenAPI's `form`
  style writes a boolean.

## Consequences

- **Narrowing the Laravel range was a breaking change.** An application on Laravel 12.0 to 12.21 has to update Laravel
  before it can install a version that carries the strict rules. [`stack.md`](../stack.md) keeps the support range, and
  says that narrowing it is breaking.
- **A boolean read as text accepts `"1"` and `"0"`**, which the contract does not write. It is kept because query and
  form clients send them, and the finding on the field says it accepts the two more
  ([ADR 0006](./0006-hold-rules-to-the-contract.md) lists it beside the other departures).
- **A whole number written as a float is refused today, with a finding.** `1.0` and `1e2` are integers for JSON Schema
  2020-12, and `integer:strict` refuses them. Laravel's `integer` beside `numeric:strict` would accept them, and the
  package keeps the single rule. One finding per request with an integer field says the request is stricter than the
  contract.
- **A JSON client of a body that is JSON and a form** can send `"42"` for an integer, and each field's finding says so.
  A runtime branch would close it ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- **A controller that reads a query parameter with `validated()`** gets the text, and casts it itself. For a boolean,
  the finding on the field gives the read, since `(bool) 'false'` is `true`.
- **What the decision leaves open** is [#133](https://github.com/Gcob/lara-spec-first/issues/133).
