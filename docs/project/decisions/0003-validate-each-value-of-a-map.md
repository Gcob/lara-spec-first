---
title: "ADR 0003: Each value of a map is validated by its own schema, and reported where that cannot be done"
audience: Users and contributors
covers: >
    The decision to read a schema written under `additionalProperties` as the rules of every value of an object that
    declares no property, to report those values where a wildcard rule would also judge a declared key or a `query`
    parameter, how `allOf` merges two value schemas, and the alternatives turned down.
read_before: >
    Asking why a map's values are validated under `*` or `field.*`, why they are not beside declared `properties`,
    changing how `allOf` merges `additionalProperties`, or reading what `spec:doctor` says about a map.
tags: [decisions, code-generation, openapi]
---

# ADR 0003: Each value of a map is validated by its own schema, and reported where that cannot be done

> **In brief**
>
> - An object that declares no `properties` and writes a schema under `additionalProperties` is a map: every value is
>   judged by that schema's own rules, under one wildcard key (`*` at the root, `labels.*` nested).
> - Beside declared `properties`, and at the root beside a `query` parameter, the values are reported and not validated:
>   the wildcard would judge the declared key or the parameter too. `spec:doctor` reports them.
> - Turned down: dropping the schema in silence, and ordering the rules so the wildcard spares a declared key, which
>   rests on how Laravel happens to merge them.

**Status:** Superseded by [ADR 0014](./0014-judge-undeclared-keys-by-name.md) · **Card:**
[#109](https://github.com/Gcob/lara-spec-first/issues/109) · **Pull request:**
[#170](https://github.com/Gcob/lara-spec-first/pull/170)

## Context

`additionalProperties` is the keyword that says what the keys an object did not declare may hold. `false` forbids them
and `true` allows anything. A schema (`additionalProperties: {type: string, maxLength: 30}`) says every one of them is a
string of at most 30 characters, which is how an OpenAPI document writes a map of labels, of settings, of counters.

Until now the package read the schema and did nothing with it. A map body was reported `Ignored`, its values reached
`validated()` unchecked, and a request the contract refuses answered `200`.

Laravel has one notation for "each key at this level": a wildcard, `labels.*`. It has three properties that decide what
the package can promise:

- **The wildcard reaches the keys present in the data, and only those.** It never asks for a key that is absent, so it
  carries a value's rules and none of a key's presence.
- **It reaches every key present at its level, declared or not.** Beside a rule for `id`, `*` gives `id` its rules too.
- **Whether an explicit key keeps what the wildcard adds depends on the order of the rules array.** Written after the
  wildcard, the explicit key replaces what the wildcard merged into it. Nothing in Laravel's documentation says so.

A body shares its validated data with the `query` parameters of the same operation, so a wildcard at the root of the
body reaches the keys of the query string as well.

## Decision

**A map is validated value by value.** The object has no `properties` and a value schema. Each value gets that schema's
own rules, the ones any field of that type gets, under one wildcard key written by the same code that writes a list's
elements:

```php
'labels' => ['sometimes', 'array'],
'labels.*' => ['string', 'max:30'],
```

At the root the key is `*`. A value is present by being in the object, so the rule carries no presence, as an element of
a list carries none. Strict or loose types follow the body as for any other field: `integer:strict` in a JSON body,
`integer` in a form. A key containing a dot is the key as sent, never a path.

**A key the object requires without declaring it is judged by the wildcard too.** `required: [a]` with no `properties`
keeps its `present`, and the wildcard's rules join it. The wildcard is written after every explicit key of its object,
for the reason above. The finding that says the request "says nothing about the value" is not written there, since the
value is checked.

**An empty value schema is an open object.** `additionalProperties: {}`, or a schema that only writes keywords nothing
reads, allows any value as `true` does: no rule, and no finding.

**Where a wildcard would judge more than the values, the values are reported.** Two cases, and in both a finding names
`additionalProperties` and `spec:doctor` reports it as `Partial`:

- an object that declares `properties` beside its value schema: the wildcard would hold `id: integer` to the `string`
  the value schema asks of every other key;
- a root map beside a declared `query` parameter, since the parameter's key sits among the map's.

A map nested in such an operation is validated, because its keys sit under a name of its own.

**`allOf` merges the value schemas exactly.** When two branches each write one, the merged object's value schema is the
two combined. A value schema written by one branch also applies to the properties the other branch declares and it does
not, since JSON Schema evaluates each branch on its own: that property becomes the combination of the two. A
contradiction (an integer value schema beside a string property) is refused, as every `allOf` contradiction is.
`additionalProperties: false` in one branch closes the merged object and drops the value schema, since no extra key is
left for it to judge.

**A recursion through a map is cut on its values.** It is unrolled down to its `x-max-depth` as a recursion through a
list is, and the map at the cut must be empty, as an empty list passes there.

**Beside a `$ref` (3.1), the schema is read like anywhere else.** The keyword is no longer reported `Ignored` there.

## Alternatives turned down

- **Keep dropping the schema, and report it.** It was the reading before, and it let a request the contract refuses
  answer `200`. A generated request is supposed to refuse what the contract refuses, and a wildcard does it for the
  common case, a body that is a map and nothing else.
- **Validate beside declared properties, ordering the rules so the declared key wins.** Writing the wildcard first and
  the explicit keys after it works on Laravel today, and it is the exact reading. It was turned down because it relies
  on the order Laravel merges rules in, which it does not document and may change, and a generated request that quietly
  stops being exact after an upgrade is worse than one that says it is not. `Rule::forEach` is the documented mechanism,
  and it is a larger change than this decision, so beside declared properties stays reported until someone weighs it.
- **Validate a root map beside a `query` parameter anyway.** The parameter's own key is judged by the value schema, so a
  valid request is refused. A rule that refuses what the contract allows is the direction the package does not travel.
- **Keep the first value schema when two branches of an `allOf` write one.** It is the simplest merge, and it silently
  drops the second, so a request the second branch refuses passes.

## Consequences

- **A request that answered `200` may answer `422`:** a map's values were never checked, and now are.
- **`spec:doctor` exits `0` where it exited `2`** for a document whose only gap was a map the request now validates, and
  exits `2`, as `Partial`, for the two reported cases. A CI step that expected the old code changes.
- **A root map keyed by IDs is judged like any other.** Laravel puts the data through `array_merge()` when it expands
  `*`, which renumbers an integer-like key (`"1042"`), so the wildcard alone never reaches it. The generated `rules()`
  writes the rules again under each such key the request carries, and an error answers on the key itself.
- **An `allOf` that gave two branches contradictory value schemas is refused** where it built before.
- **A wrapper that writes `additionalProperties: <schema>` is a schema of its own,** as one that writes `true` or
  `false` already was, so its class name changes where it wrapped a single `$ref`.
- **This does not settle what a map becomes on the input DTO.** The type of a map property and of a map body is a
  decision of its own.
