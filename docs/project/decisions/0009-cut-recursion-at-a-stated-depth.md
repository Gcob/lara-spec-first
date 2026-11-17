---
title: "ADR 0009: A recursive schema states its depth with `x-max-depth`, and a payload deeper than that is refused"
audience: Users and contributors
covers: >
    The decision to validate a schema that refers to itself down to a depth the contract states, what happens to the
    field one level below that depth, why the request is stricter than the contract there and says so, the ceiling on
    the rules a recursion may unroll, how the input DTO is typed to match, and the alternatives turned down.
read_before: >
    Asking why a payload five levels deep answers `422`, where `x-max-depth` is written and what it defaults to,
    changing what a request does below the depth, touching `NullOrAbsent`, or raising the ceiling on unrolled rules.
tags: [decisions, code-generation, openapi, laravel, versions]
---

# ADR 0009: A recursive schema states its depth with `x-max-depth`, and a payload deeper than that is refused

> **In brief**
>
> - A schema that refers to itself is validated down to the depth it states with `x-max-depth` on the recursive
>   component, a whole number of zero or more, and `3` when it states none. There is no config key.
> - One level below the depth the field must be absent, or `null` where the schema allows it. A deeper payload is a
>   `422`, which is stricter than a contract with no limit, and the generated file says so in a finding that names
>   `x-max-depth`.
> - A request unrolls at most 1,000 recursion rule keys per operation. When the stated depths do not fit, they are
>   lowered, the deepest schema first and every branch of a schema at the same depth. A build is never refused.
> - The input DTO is typed to match: every level the request validates is the class of the schema.
> - Turned down: a config key, validating nothing below the depth, refusing a contract that states no depth, Laravel's
>   `prohibited` at the cut, and refusing the build past the ceiling.

**Status:** Accepted · **Card:** [#100](https://github.com/Gcob/lara-spec-first/issues/100) · **Pull requests:**
[#123](https://github.com/Gcob/lara-spec-first/pull/123) and [#125](https://github.com/Gcob/lara-spec-first/pull/125)

## Context

A schema can refer to itself, directly (`Comment` holds a list of `Comment`) or through another one (`Author` holds
`Book`s and `Book` holds an `Author`). Such a contract is valid and describes a tree that never ends, and no rule set
reaches an infinite depth.

The rules used to stop at the first repeat, and nothing below it was validated: `parent: "oops"` passed. The input DTO
read those fields as `mixed`, because a class built from a value nothing checked is a `TypeError`, a `500` where the
request owes a `422`. The package had to choose how deep to validate, who says so, and what a request below that depth
answers.

## Decision

**A recursive schema states its depth with `x-max-depth`, written on the recursive component.** It is a whole number of
zero or more, how many times a payload may re-enter the schema, and `3` when the schema says nothing. It reads the same
at 3.0 and 3.1 (`max-depth-3.0.yaml` and `max-depth-3.1.yaml`), and a value that is not a whole number (text, a negative
number, a fraction) is refused where the document is read, as the lifecycle extensions are. There is no config key: the
contract owns the shape of a payload, and a depth set outside it would be a second source of truth. It is the package's
first schema-level extension.

**A level is one crossing of the recursion, and the limit at a crossing is the depth of the schema it re-enters.**
`x-max-depth: 2` on `Comment` lets a payload re-enter `Comment` twice: a reply, and a reply to that reply. A recursive
pair never has to agree with itself: entered at `Author` it reads `Author`'s depth, and entered at `Book` it reads
`Book`'s. A depth of zero cuts the recursive field itself.

**One level below the depth, the field must be absent, or `null` where the schema allows it.** A payload deeper than
that answers `422` on the first field below the depth. The rule depends on what the recursive schema allows:

- **Not nullable:** Laravel's `missing`, which refuses `null`, `""`, `[]`, `{}` and any value.
- **Nullable:** the presence rule the contract asks for, then `Gcob\LaraSpecFirst\Validation\NullOrAbsent`, a rule of
  the package's own. `missing` would refuse the `null` the contract allows, `prohibited` lets `""` and `[]` through, and
  `nullable` does not skip an implicit rule, so no rule Laravel ships says "null or absent". The class is implicit, so
  Laravel calls it for an absent key.
- **What the contract asks of the key stays beside the cut.** A required, non-nullable recursion that reaches the cut is
  a `422`, which is the contract's own tree: it never ends. A required nullable one can end in `null` at the last
  validated level.
- **A list of the schema is cut on its elements, and a map on its values**
  ([ADR 0003](./0003-validate-each-value-of-a-map.md)). An empty list or an empty map passes at the cut, since it
  carries no data and the depth exists to limit how much data a payload carries. One element below the depth, or one
  value below it, answers `422` on that element or key.

**The request is stricter than the contract there, and says so.** A payload five levels deep is valid against a schema
that states no limit. Each recursive schema the walk cuts gets one finding in the generated file, which names the
schema, the depth that applied and whether the schema stated it, the first field the request refuses, and `x-max-depth`
as the lever. It is an example of the stricter case [ADR 0006](./0006-hold-rules-to-the-contract.md) allows.

**Unrolling is capped at 1,000 rule keys per operation, and depths that do not fit are lowered.** A schema that recurses
once per level adds one copy of its rules per level. One that recurses `k` times per level grows as `k` to the power of
the depth, and a request evaluates every key it holds: a binary tree stated at `x-max-depth: 20` is over three million
keys. When a walk passes the ceiling, it is thrown away and redone with the depths lowered, the deepest schema first,
until the rules fit. Every branch of a schema is cut at the same depth, so none is left unvalidated and the typed DTO
below holds. The same finding says what the contract stated and what applies instead. The build is never refused: a
depth of zero unrolls nothing and always fits.

**The input DTO is typed to match.** A recursive node is the class of the schema it points back at, so every level the
request validates is that class, and the key below the last one is `Optional`, or `null` where the schema is nullable.
Two shapes are not unrolled, since the request has no ancestor to read a depth from. They keep their presence rule and a
finding that says nothing below the field is validated, and the DTO reads them as `mixed`:

- a recursion through a schema with no name, since an inline schema has no position a marker can be found by;
- a field that is two markers at once (two `allOf` branches pointing back at different components), where the walk
  follows one ancestor and unrolling it would drop the other in silence.

Two more are validated by the request and still `mixed` in the DTO, because no class of the ancestor describes them: a
marker with `properties` written beside it, and a marker whose target is not an object with properties (an untyped
component, an array).

## Alternatives turned down

- **A config key, or a config default the extension overrides.** The contract owns the shape of a payload, and a depth
  set outside it is a second source of truth.
- **Stop validating below the depth, and keep the field `mixed`.** It is what the rules did before, and the typed DTO
  would then be built from input nothing checked: `parent: "oops"` below the last rule is a `TypeError`, a `500` where
  the request owes a `422`.
- **Refuse a contract that states no depth.** It turns away a contract that is valid, and the default of `3` lets the
  build go on.
- **Laravel's `prohibited` at the cut.** It means "absent or empty", so `null`, `""` and `[]` pass, and a typed DTO
  would be built from an empty array. `missing` is the key being absent, which refuses all three.
- **Refuse an empty recursive list at the cut.** It carries no data, which is what the depth limits, and refusing it
  would turn away `"children": []` from a contract that allows it.
- **Past the ceiling, refuse the build, or stop unrolling that branch and keep only presence.** The first turns away a
  valid contract. The second leaves part of the tree unchecked while the DTO is typed, and one branch of a schema would
  be validated deeper than another.

## Consequences

- **A request is stricter than a contract with no limit.** A contract whose real traffic recurses deeper than three
  levels states the depth it needs. The finding says so ([ADR 0006](./0006-hold-rules-to-the-contract.md)).
- **`x-max-depth` and `NullOrAbsent` are public names.** A generated request imports the class, so its name and
  namespace are frozen with the rest of what a generated file names.
- **A branching schema stated deep is validated less deep than stated.** A binary tree stated at 20 is validated 7
  levels deep, on `left` and `right` alike, and the finding says the stated depth and the one that applies.
- **An `x-max-depth` beside a `$ref` is reported `Ignored` and does nothing**
  ([ADR 0008](./0008-honor-keywords-beside-a-ref.md)): a recursion reads the depth of the schema it points back at, so
  it belongs on the recursive schema.
- **A recursion through a schema with no name, or through a field that is two markers at once, validates nothing below
  the field,** which is looser than the contract. The finding in the generated file says so, and `x-max-depth` does not
  lift it, since there is no schema to write it on.
