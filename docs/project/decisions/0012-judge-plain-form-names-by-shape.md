---
title: "ADR 0012: A plain form name is judged by shape beside the names a form nests under it"
audience: Users and contributors
covers: >
    The decision to judge a name of a body read as a form only when its value is not an array when other declared names
    are delivered below it (`page` beside `page[size]`), to read the value of such a name in `dto()` only when it is not
    an array, what the shape check cannot tell and what it states as stricter, and the alternatives turned down.
read_before: >
    Asking why a plain form name is wrapped in `Rule::when()`, why `dto()` drops an array, why a required `page` beside
    `page[size]` is `Optional` on the DTO, or changing how a form body's names that PHP nests are validated.
tags: [decisions, code-generation, openapi]
---

# ADR 0012: A plain form name is judged by shape beside the names a form nests under it

> **In brief**
>
> - A name of a body read only as a form, declared beside names a form nests under it (`page` beside `page[size]`,
>   `filter[a]` beside `filter[a][b]`, `ids` beside `ids[]`), keeps its rules, judged only when its value is not an
>   array. The nested names keep theirs. It is the rule [ADR 0002](./0002-judge-plain-query-names-by-shape.md) set for
>   the query string.
> - `dto()` reads such a name only when its value is not an array, and a required one is `Optional` on the DTO: the
>   array of the nested names stands in for it, so a required `page` is satisfied by `page[size]=3` alone.
> - The request is stricter than the contract in two cases, and the generated file says so: a `dependentRequired` that a
>   shaped name triggers, and a name the contract asks for beside only `*` names.
> - Turned down: giving both names no rule, which let `page=abc` through, and shaping a name the contract asks for
>   beside a `*` name, which turned a 422 into a 200.

**Status:** Accepted · **Card:** [#168](https://github.com/Gcob/lara-spec-first/issues/168)

## Context

PHP parses a form body the way it parses the query string, before Laravel sees it: `page[size]=3` arrives as
`page => [size => 3]`, `user.name` as `user_name`, and `ids[]` as the next free index of a list.
[ADR 0002](./0002-judge-plain-query-names-by-shape.md) decided what a `query` name beside the names PHP nests under it
gets: its rules behind a shape check. The form body had an answer of its own, which that ADR took as its model before it
changed it for the query string: two names where one is delivered below the other got no rule at all, because no rule
was thought to tell their values apart.

That reason is false. `page=2` delivers a scalar and `page[size]=3` an array, and a rule can tell them apart, as it does
on the query string. The answer cost something real: a contract that declares `page` and `page[size]` let `page=abc`
through, left a required `page` unasked for, and kept both names out of `dto()`.

Two facts of the DTO shape what a fix has to do. `from()` casts what it reads, and `(int)` of any non-empty array is
`1`, so a `dto()->page` read from `page => [size => 3, evil => 1]` would be a silent wrong `1`. And two names that
arrive under one key (`user.name` and `user_name`) have no rule, so a property typed `string` for one is handed the
array of a name below them: a `TypeError` on a valid request.

## Decision

**A name beside names a form nests under it keeps its rules, behind Laravel's own `Rule::when()`.** The rules apply when
the value is not an array, the array a form builds stands in for the nested names, and each nested name keeps its own
rules under the key PHP delivers it as. It holds at any depth (`filter[a]` beside `filter[a][b]`), in a chain of three
(each upper name is shaped), and for a list: a form has no append notion, so `ids[]` is read as `ids.0` and `ids` is
shaped above it. The array branch is `['array']` when a name below has a rule, and `['exclude']` when none has, as on
the query side:

```php
'page' => Rule::when(
    fn (Fluent $input): bool => ! is_array($input->get('page')),
    ['sometimes', 'integer', 'min:1'],
    ['array'],
),
'page.size' => ['sometimes', 'integer'],
```

**`dto()` reads the name only when it is not an array.** The generated request lists such names in `SCALAR_NAMES`, and
`contractNames()` drops the array after it has read each nested name out of it, so `page[size]=3` reaches the DTO as
`page[size]` and `page` is `Optional`. The DTO class is untouched: it serves JSON and nested uses too, which is why
`contractNames()` exists. The key two names deliver under one is in the list too, when a name below it has a rule.

**A required shaped name is `Optional` on the DTO, with a finding.** The array stands in for it, so the payload cannot
promise it. A shaped name that is not required is already `Optional`.

**What the shape check cannot tell, stated.** A name whose presence the contract asks for (`required`, a dependent of a
`dependentRequired`, or a required name of an optional body, through its "all or none") is satisfied by the array of the
names nested under it: `page[size]=3` alone passes a required `page`. A name that nothing asks for has no such blind
spot, and neither has a rule that judges the nested name.

**Stricter than the contract, with a finding in the generated file:**

- **A `dependentRequired` a shaped name triggers.** `dependentRequired: {page: [other]}` asks for `other` with
  `present_with:page`, and a form that sends only `page[size]=3` delivers an array under `page`, which `present_with`
  counts as `page` sent. `other` is asked for where the contract never sees `page`.
- **A name the contract asks for, beside only `*` names.** `page` beside `page[*]` is shaped only when nothing asks for
  `page`. When something does, it keeps its rule as it was, and a valid `page=2&page[*]=5` is refused (PHP keeps the
  later name, so `page` arrives as an array).

**What keeps no rule, as before.** Two names under the exact same key (`user.name` and `user_name`): the value of one is
the value of the other. A name below them keeps its own rule, and they keep their finding. A name typed `array` or
`object` beside a name below it: its own value may be an array, so no rule can tell it from the array a form builds, and
`Rule::when()` would never run its rules. An untyped name is shaped, as on the query side. A body that is JSON as well
keeps the collision, since one rule set serves both clients and `rules()` does not branch on the media type. A `*` name
has no rule at all, as before: it claims its path in a body read only as a form, to shape the name above it.

**A closed body closes the level a shaped name is delivered at through the array branch.** `filter` is closed to `a` and
`c`, and `filter.a` is the key of `filter[a]`, whose rules judge a value that is not an array. A rule of its own on
`filter.a` would refuse that value, so the keys declared below it go in the array branch of the shape check instead:
`['array:b']`, written `'filter.a' => Rule::when(..., ['sometimes', 'string'], ['array:b'])`. `filter[a][evil]=1` and
`page[evil]=1` are refused, as the contract refuses them, where `['array']` accepted them and kept only the declared key
in `validated()`. A key `array:` cannot name (a comma in it) keeps `['array']` and the level open, with a finding, and a
name that keeps no shape check (typed `array` or `object`, or in a collision) keeps its level open as before.

## Alternatives turned down

- **Keep the collision.** Both names without a rule is the simplest reading, and it was the one in place. It let
  `page=abc` and `page[size]=abc` through, left a required `page` unasked for, and kept both names out of `dto()`, for a
  reason ADR 0002 had already shown false.
- **Shape every upper name, whatever the contract asks of it.** Beside a `*` name, `page[*]=5` alone would then satisfy
  a required `page` with the dropped array: a 422 that becomes a 200, which ADR 0006 and the principle it states call a
  defect. A name the contract asks for keeps its rule instead, and the request says it is stricter.
- **Leave out the dependency of a shaped trigger.** `page=2` without `other` would then pass, which the contract
  refuses, where today it is refused. The stricter finding is the smaller departure.
- **Change the DTO class instead of `contractNames()`.** The class serves a JSON body and nested uses as well, where an
  array under `page` is a value of its own.
- **Replace ADR 0002.** Its decision still holds for the query string, and an ADR is never edited into another.

## Consequences

- **A request that answered `200` can now answer `422`:** `page=abc` and `page[size]=abc` for an integer, a required
  `page` with nothing sent, and `page=2` alone where an optional body or a `dependentRequired` asks for more. The
  [CHANGELOG](../../../CHANGELOG.md) lists them as a breaking change.
- **The shape check has one blind spot,** the one the query side has: `page[size]=3` satisfies a presence the contract
  asks of `page`. The [guide](../../guide/code-generation/request-validation.md#one-rule-set-body-and-query) and the
  CHANGELOG state it.
- **A closed body refuses an undeclared key below a shaped name** (`page[evil]=1` beside `page` and `page[size]`), which
  it accepted. The CHANGELOG lists it as a breaking change.
- **`dto()` carries the nested names again:** `page[size]` reaches it, and a required one is no longer `Optional`.
- **`spec:build` stops warning** for these names, since they have rules, and `spec:doctor` stays silent on them.
- **This does not settle** a form body's appended name (`ids[]` read as `ids.0`: a second value goes to `ids.1`,
  unjudged), tracked by [#174](https://github.com/Gcob/lara-spec-first/issues/174), nor a `*` name beside a plain name
  outside a body read only as a form, tracked by [#175](https://github.com/Gcob/lara-spec-first/issues/175).
