---
title:
    "ADR 0014: The values of the keys an object does not declare are judged by a guard that spares the declared names"
audience: Users and contributors
covers: >
    The decision to validate the value schema of an object that declares properties beside it, with a package rule
    (`UndeclaredKeys`) on the object's wildcard that gives a declared key exactly its own rules, how it is recorded and
    written, the declared names it cannot spare and the finding each leaves, what `validated()` holds, the cost of a
    large map and the remedy, a root map beside a `query` parameter, and the alternatives turned down. It also restates
    what of ADR 0003 still holds.
read_before: >
    Asking why a map's values are validated beside declared `properties` or a `query` parameter, why a generated request
    imports `UndeclaredKeys`, why a declared name with a dot keeps `spec:doctor` at `Partial`, why `validated()` holds
    keys no property names, or changing how the wildcard of a map is written.
tags: [decisions, code-generation, openapi]
---

# ADR 0014: The values of the keys an object does not declare are judged by a guard that spares the declared names

> **In brief**
>
> - An object that declares `properties` and writes a schema under `additionalProperties` has that schema validated: the
>   keys the object does not declare get the value schema's rules, and a declared key keeps exactly its own, whatever
>   the order of the rule array.
> - The wildcard is a package rule, `Gcob\LaraSpecFirst\Validation\UndeclaredKeys`, that the generated request writes as
>   the key's value. It reads the key's names, never the data, which is what keeps it affordable.
> - A declared name Laravel cannot tell from an undeclared key by what it sees keeps the report (`Partial` in
>   `spec:doctor`), with a finding that says why. A root map beside a `query` parameter is validated too: the guard
>   spares the key the parameter arrives under.
> - Turned down: ordering the rules so the declared key wins, building a rule for every undeclared key at request time,
>   and `Rule::forEach`, which takes 22 seconds for a map of 10,000 values.
> - This supersedes [ADR 0003](./0003-validate-each-value-of-a-map.md), and restates what of it still holds.

**Status:** Accepted · **Card:** [#169](https://github.com/Gcob/lara-spec-first/issues/169) · **Pull request:**
[#194](https://github.com/Gcob/lara-spec-first/pull/194)

## Context

[ADR 0003](./0003-validate-each-value-of-a-map.md) validated the values of a map, an object that declares no
`properties` and writes a value schema, under one wildcard (`labels.*`). It left the object that declares properties
beside a value schema reported and not validated, for a reason it named: a wildcard reaches every key present at its
level, declared or not, so `id: integer` beside `additionalProperties: {type: string}` would have been held to the
`string` as well. A request the contract refuses answered `200` there, and `spec:doctor` exited `2` for it.

Two ways out were on the table. The first is the order of the rules array: written after the wildcard, an explicit key
replaces what the wildcard merged into it. It was turned down because nothing documents it. The second is
`Rule::forEach`, which ADR 0003 named as the documented mechanism and did not weigh.

Measured on Laravel 13.31, a map of `n` integers beside one declared key:

| Keys (JSON size) | `Rule::forEach` guard | `UndeclaredKeys` | Plain `*` |
| ---------------- | --------------------- | ---------------- | --------- |
| 1,000 (11 KB)    | 0.23 s                | 0.02 s           | 0.01 s    |
| 4,000 (49 KB)    | 3.4 s                 | 0.08 s           | 0.04 s    |
| 10,000 (125 KB)  | 21.7 s                | 0.29 s           | 0.08 s    |
| 64,000 (916 KB)  | minutes               | 6.8 s            | 0.43 s    |

`Rule::forEach` is quadratic because each key makes Laravel undot the whole gathered data. A rule that ignores the data
is quadratic as well, in Laravel's own expansion of a compiled rule under a wildcard (the implicit attributes are merged
again for every key), but its knee is about ten times later: about 7 seconds per megabyte of map near 1 MB.

## Decision

**The value schema judges the keys the object does not declare, and a declared key keeps its own rules.** The wildcard's
rules are written as before (`meta.*`, and `meta.*.n` for an object value), each inside an `UndeclaredKeys` that carries
the names to spare by step:

```php
'meta' => ['sometimes', 'array'],
'meta.id' => ['sometimes', 'integer:strict'],
'meta.*' => new UndeclaredKeys([1 => ['id']], ['string']),
```

The rule compiles to no rule when the key's step (`meta.c` has `c` at step 1) is a spared name, and to the value rules
otherwise. It is the key's value itself, never an element of a list: Laravel compiles it only there. The order of the
array no longer matters, and a declared key never gets the value schema's rules.

- **Every rule key written under the wildcard is guarded**, so a value's own children never reach a declared key. A
  value that is a map itself adds its own step to the same guard. The step counts unescaped dots, since the dot of an
  escaped name is not a step.
- **A map with no declared property keeps the plain wildcard** of ADR 0003, and so does its generated file.
- **Spared names are strings.** PHP turns a declared `"2024"` into an integer as an array key, and the comparison is
  strict.
- **A root that declares properties beside a value schema repeats the value rules under the integer-like keys the body
  carries,** as a map root does, and the repeat skips the declared names: copying over a declared `"2024"` would give it
  the value schema's rules in place of its own. The guard and the repeat read one list of names.
- **`validated()` holds the undeclared keys the guard judges,** beside the declared ones, at the root and nested. The
  DTO of an object that declares properties carries the declared ones only, and its file says so.
- **A declared name the guard cannot spare keeps the report.** Laravel replaces a `.` in a data key with a placeholder
  it keeps private, and has no name for a `*`, so the key the guard compares is not always the name the contract wrote.
  The values then stay reported: one finding names the reason, `spec:doctor` says `Partial`, and no value rule is
  written. The cases are a name holding `*`; a name holding `.` read as JSON, or below the top level of any body; at the
  top of a body read only as a form, a name PHP delivers under two steps or more (`page[size]`), since sparing `page`
  would leave an undeclared scalar `page` unjudged; and at the top of a body that is JSON and a form at once, a name PHP
  rewrites, since one rule set cannot spare both readings.
- **A form-only root's name PHP delivers under one other name is spared, and stated as unenforced.** `user.name` arrives
  as `user_name`, so the guard spares that key. A literal undeclared `user_name` arrives under the same key and is
  judged by the declared name's rules only, where the contract asks for the value schema's: a constraint lost, so the
  finding counts as unenforced and `spec:doctor` says `Partial`. No public Laravel API gives a rule the original key,
  and PHP itself merges the two names.
- **An undeclared key PHP rewrites** (`a b` as `a_b`) reaches the value schema under its new name. That loses no
  constraint, so it is information, like the same finding on a map root.
- **A root map beside a `query` parameter is validated, and the guard spares the parameter's key.** ADR 0003 reported
  it, since the validated data holds the body and the query string and a plain wildcard would hold the parameter's key
  to the value schema. The root's guard spares the keys the parameters arrive under (`page` for a parameter written
  `page[size]`) beside the declared names, so the parameter keeps its own rules and a body key of that name is left out
  of the validated data, which is information, as for a map root. The keys of a `query` parameter never hold a `.` (PHP
  rewrites it) and a name holding `*` has no key to spare, so the guard can always spare them.

### What of ADR 0003 still holds

A map is validated value by value under a wildcard that carries no presence, strict or loose by the body. A key with a
dot is the key as sent, never a path. A key the object requires without declaring it is judged by the wildcard too, and
the finding that says the request "says nothing about the value" is not written there. An empty value schema is an open
object. `allOf` merges two value schemas exactly, and a value schema beside a `$ref` is read like anywhere else
([ADR 0008](./0008-honor-keywords-beside-a-ref.md)). A recursion through a map is cut on its values
([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)). A root map keyed by IDs keeps its keys, since the rules are
written again under each integer-like key.

## Alternatives turned down

- **Order the rules so the declared key wins (the wildcard first, the explicit keys after).** It works on Laravel today.
  It relies on how Laravel merges rules, which no documentation promises, and it is exact only for a value schema that
  is one scalar: a value that is an object puts `meta.*.n` on the children of a declared key as well (measured, a `422`
  on a valid `meta.a.n`).
- **Build a rule for every undeclared key at request time,** the way the integer-like keys are. It would need escaping
  for every key, would not reach a map nested in another or under a list without reimplementing the wildcard, and moves
  more of `rules()` into the request, which the file is meant to show whole.
- **`Rule::forEach`, the documented mechanism.** It is exact and costs 22 seconds for a map of 10,000 values, which is a
  request nobody can serve. A rule that never receives the data does the same job 75 times faster there.
- **Spare a declared name by reading Laravel's placeholder for a dotted key.** The hash is a protected static and the
  closures run inside the validator's constructor, so no generated code can read it, and the package would depend on a
  private detail.
- **Keep reporting the values beside declared properties.** It leaves a request the contract refuses answering `200`.

## Consequences

- **A request that answered `200` may answer `422`:** an undeclared key beside declared properties, and a key of a root
  map beside a `query` parameter, now has its value checked.
- **`spec:doctor` exits `0` where it exited `2`** for a document whose only gap was such a map or a root map beside a
  `query` parameter, and still exits `2` for the cases the guard cannot spare. A schema used in a request and in a
  response still exits `2` for the response's own finding.
- **A generated request imports `Gcob\LaraSpecFirst\Validation\UndeclaredKeys`:** a class name a generated file depends
  on, frozen with the rest at 1.0, and so are its constructor and `below()`, which the generated file calls.
- **A large map costs validation time past about 1 MB,** quadratic in Laravel's own expansion: about 7 seconds per
  megabyte near 1 MB, and 0.3 seconds for 10,000 keys. Cap the request body at the web server (nginx's default
  `client_max_body_size` is 1 MB; PHP's `post_max_size` does not bound a JSON body in time).
- **`validated()` and `dto()` differ by the map's keys** for an object that declares properties. Carrying the values on
  the DTO is a decision of its own.
