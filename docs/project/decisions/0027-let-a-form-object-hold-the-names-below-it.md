---
title: "ADR 0027: A form name typed `object` holds the names a form nests under it"
audience: Users and contributors
covers: >
    The decision that, in a body read only as a form, a name typed `object` keeps its own rules beside the names a form
    nests under it, and each nested name keeps its own, where ADR 0012 withheld both; the four cases that keep the
    collision and why (an `array`, a map object, an object that declares the key a nested name arrives at, an object
    beside JSON); how the object's level is open or closed; which readings are counted gaps (what an object makes
    required when only the nested names are sent) and which is a stated reading (a required object); and the
    alternatives turned down. It replaces two passages of ADR 0012 for an `object` and amends one line of ADR 0024.
read_before: >
    Asking why `p` typed `object` beside `p[s]` has rules in a form body, why `x[b][evil]=1` passes beside an `x[b]`
    typed `object`, why a required object is satisfied by the names nested under it, why `spec:doctor` counts an
    object's required keys in a form body as stricter than the contract, why an `array` or a map object still keeps no
    rule beside a name below it, or changing how a form body's names are judged by shape.
tags: [decisions, code-generation, openapi, compatibility]
---

# ADR 0027: A form name typed `object` holds the names a form nests under it

> **In brief**
>
> - A form delivers an object only as an array. So in a body read only as a form, `x[b]` typed `object` and `x[b][c]`
>   are judged by one array without contradiction: the object keeps the rules it has alone, and the nested name keeps
>   its own, keyed by the path PHP delivers it under (`'x.b' => ['sometimes', 'array']`,
>   `'x.b.c' => ['sometimes', 'integer']`). This holds at any depth and at the top level.
> - Four cases keep the collision, each for its own reason: a name typed `array` (its items arrive at the level of the
>   nested name), a map object (its rule for every other key judges the nested name too), an object that declares the
>   key a nested name arrives at (two values reach one key), and an object in a body that is JSON as well.
> - The object's level is its own: an open object accepts another key, and a closed one takes its properties and the
>   keys of the names below it.
> - What an object makes required when only the nested names are sent (a `dependentRequired` it triggers, the required
>   keys of an optional object) is stricter than the contract and is a counted gap, under `stricterRequired`. A required
>   object satisfied by the nested names alone is a stated reading, not counted.
> - Turned down: keeping the collision, a shape check, 0.2.0's `array:<nested keys>` in place of the object's rule,
>   withholding only the object's colliding property, releasing a map, and `stricter()` for the two stricter readings.
> - It replaces the two passages of [ADR 0012](./0012-judge-plain-form-names-by-shape.md) that kept an `object` in the
>   collision, and amends one line of [ADR 0024](./0024-hold-rules-and-report-every-gap.md).

**Status:** Accepted · **Card:** [#221](https://github.com/Gcob/lara-spec-first/issues/221) · **Pull request:**
[#258](https://github.com/Gcob/lara-spec-first/pull/258)

## Context

[ADR 0012](./0012-judge-plain-form-names-by-shape.md) judges a plain form name by shape and keeps a name typed `array`
or `object` out of it when a name below it exists: "its own value may be an array, so no rule can tell it from the array
a form builds". It also leaves the level such an object holds open. That reason holds for an `array`, whose items arrive
at the same level as the nested name (`x[b][]=1` is `x.b.0`, and a rule for its items, `x.b.*`, would judge `x[b][c]` as
one of them). It does not hold for an `object` in a body read only as a form: a form has no object value of its own, it
delivers an array, so the object's rules (`array`, its properties, its key check) and the nested name's rules judge the
same array and cannot contradict each other.

Keeping both withheld cost the contract's own rules. Below the top level, a closed body wrote `array:<the nested keys>`
in place of the object's rule (0.2.0's overwrite), which refused the object's other keys and its own properties and
dropped its presence and required keys. At the top level (`p` beside `p[s]`) neither name got a rule, and both were
reported as unchecked.

## Decision

**In a body read only as a form, a name typed `object` no longer collides with the names below it, at any depth.** It
keeps every rule it has alone, and each nested name keeps its own, keyed by the path PHP delivers it under:

```php
'x.b' => ['sometimes', 'array'],
'x.b.c' => ['sometimes', 'integer'],
```

`x[b]=abc` and `x[b][c]=abc` answer `422`. The top level is the same (`p` beside `p[s]`): one rule at every depth is
simpler to read than "below the top only". The two names are no longer reported as unchecked.

**Four cases keep the collision, with its true reason in the finding:**

- **A name typed `array`.** A form delivers its items at the level of the nested name, so the rules for its items would
  judge the nested name as one of them, and no rule can tell an item from it.
- **A map object**, one whose `additionalProperties` is a schema that judges something (`{}` is an open object and is
  released). Its value rule judges every key of its level, the nested name's key included, so releasing it would refuse
  `x[b][c]=abc` beside a map of integers, with no finding.
- **An object that declares the key a nested name arrives at** (`x[b]` with a property `c`, a required `c` or a
  `dependentRequired` that names `c`, beside `x[b][c]`). The two values reach one key (`x.b.c`), as two collided names
  do. Withholding only the property would mean cutting one child out of an object's rules, which is not worth the
  complexity for a case this rare.
- **An object in a body that is JSON as well.** A JSON client's object can hold a key of the same name as the one a form
  delivers inside it (`{"p": {"s": "x"}}` beside `p[s]`), so no rule at `p.s` judges one without the other. This is
  [ADR 0012](./0012-judge-plain-form-names-by-shape.md)'s handling of a body that is JSON and a form, unchanged.

**An object inside a collided group stays withheld, and the name below it keeps its rule,** as a name below any collided
pair does (`x[b]` beside `x[b]y`, both `x.b`, and `x[b][c]`). This does not depend on the order the names are declared
in: an `array` above can withhold the object later in the same pass, so the withheld names are settled once the pass
ends.

**The object's level is its own.**

- An open object leaves its level open, as the contract does: `x[b][evil]=1` passes, as it does for `x[b]` alone, and no
  finding is written, since it is not a gap.
- A closed object (`additionalProperties: false`) takes its own properties and the keys the nested names enter it at
  (`'x.b' => ['sometimes', 'array:d,c']`), in an open body as in a closed one. Beside a `*` name or an appended name
  (`p[*]`, `p[]`) its level stays open instead, since `array:` cannot name a `*` key (Laravel hides it behind a
  placeholder) or refuses a second appended value, and the file says undeclared keys are not refused.
- In a closed body, the loop that closes each level to its declared keys skips an object's level instead of leaving it
  open for a value or overwriting the object's rule. A closed object's level is still kept, so a key no rule judges
  below it (a collided pair) is left out of `validated()`.

**A required object is satisfied by the nested names alone, and that is a stated reading, not a counted one.** The array
a form builds for the nested names stands in for the object, so `x[b][c]=5` alone passes a required `x[b]`, its DTO
property is `Optional`, and the generated file says so. The reason is the encoding: a form has no way to send an empty
object, so a valid form client whose `x[b]` holds nothing omits it, and a rule that asked for a key of `x[b]` other than
the nested ones would refuse that client. A standard form reading is information by
[ADR 0024](./0024-hold-rules-and-report-every-gap.md), and the same reason makes a required deepObject uncounted in
[ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md). It is looser than the contract, so it sits beside ADR
0024's required plain query name, not beside a stricter case.

**Two readings are stricter than the contract, and counted.** A form that sends only the names nested under an object
delivers an array under it, which the rules read as the object sent, though the contract never sees the object then:

- **A `dependentRequired` the object triggers.** `x[b]` makes `y` required, and `x[b][c]=5` alone answers `422` on `y`.
- **The required keys of an object the contract does not require** (or of any object in a partial body, which asks for
  no presence). `x[b]` requires `d`, and `x[b][c]=5` alone answers `422` on `x.b`. The required keys of a required
  object are not stricter: the contract refuses a body without the object anyway.

Matching the contract here would cost only the package's own work: the keys below an object are known when the rules are
built, so asking for `y` or `d` only when the array holds another key would be exact. ADR 0024 does not let that be an
uncounted stricter case, since its reason is not Laravel's. They are gaps, recorded under `stricterRequired`, the entry
[ADR 0026](./0026-read-a-patch-body-by-its-media-type-then-the-app.md) created for a merge patch, with the names the
request asks for (`y`, `x[b].d`) as its fields. The build counts them and `spec:doctor` exits `2`. The doctor's sentence
for that entry now has two causes, chosen by the media types the rule set reads: the merge patch wording is unchanged,
and a form-only body says the request "asks for names a form may leave out when it sends only the names nested under an
object".

**What `dto()` gets.** An object with no properties is a map on the DTO, so it gets the whole array under it, the nested
names' keys included, and each nested name is also a property of its own.

**The query string is untouched.** A `deepObject` is read by its brackets, and
[ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md) and
[ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md) hold for it.

## Alternatives turned down

- **Keep the collision.** It is what 0.2.0 did for the top level, and it leaves a valid, typed contract with no rule for
  either name. The reason it rested on is false for an `object` in a form.
- **Judge the object by shape** (`Rule::when()` on whether its value is an array). An object is always an array in a
  form, so the branch for a value that is not an array would never run, and the object's rules would never be written.
- **0.2.0's `array:<nested keys>` in place of the object's rule.** It refuses the object's other keys and its own
  properties, and drops its presence and its required keys, all of which the contract allows or asks for.
- **Withhold only the object's colliding property,** for an object that declares the key a nested name arrives at. It
  cuts one child out of an object's rules, for a case that is rare.
- **Release a map and spare the nested keys in its guard.** The guard would need to know every key a nested name enters
  the map at, in a rule Laravel writes as one wildcard. The map keeps the collision, as an `array` does, and the finding
  says why.
- **`stricter()` for the two stricter readings.** ADR 0024 reserves it for what Laravel cannot do exactly. These two can
  be coded exactly, so leaving them uncounted would hide the package's own cost.

## Consequences

- **The object's level follows its own `additionalProperties`.** An open object accepts an undeclared key, as the
  contract says, where 0.2.0's body refused it, and a closed object takes the nested names' keys beside its properties.
- **Requests that answered `200` now answer `422`,** to a client that asks for JSON: `p=abc` and `p[s]=abc`, a required
  top-level `p` left out, a required nested object left out of a closed body, and a required key of the object. The
  CHANGELOG lists it as breaking, and the requests that answered `422` and now answer `200` (`x[b][evil]=1` beside an
  open object, `x[b][c]=5` beside a closed object in an open body) are in it too.
- **Which readings are counted is decided by the reason.** The trigger's dependents and an optional object's required
  keys are counted, since the package could match the contract. A required object's stand-in is not, since a form cannot
  send an empty object.
- **One line of ADR 0024 is amended, not edited.** Its line that a stricter case is never counted holds for a stricter
  reading with a Laravel-level reason. A stricter reading without one is a counted gap under `stricterRequired`, as ADR
  0026 decided for a merge patch and this record does for a form object. ADR 0024 keeps its wording.
- **ADR 0012 keeps its wording and its status.** Most of it still holds. This record replaces two passages for an
  `object`: "What keeps no rule, as before", which keeps a name typed `array` or `object` beside a name below it in the
  collision, and the sentence of "A closed body closes the level a shaped name is delivered at through the array branch"
  that leaves the level of a name typed `object` open.
- **Coding the two stricter readings exactly is [#257](https://github.com/Gcob/lara-spec-first/issues/257)'s.** The
  `Known limits` of the CHANGELOG say so.
