---
title: "ADR 0015: The values of a map body are typed by their schema, beside a query parameter too"
audience: Users and contributors
covers: >
    The decision to type the values of a map or open-object body on the input DTO by their schema in every case, now
    that the request judges them beside a `query` parameter, to drop the `mixed` type and the pass over every request
    that decided it, and what of ADR 0004 still holds: the one `additionalProperties` property, `validated()` holding
    every key, and the class per schema decided before any is registered. It also says how the request side now matches
    the response side.
read_before: >
    Reading `$dto->additionalProperties` in a controller, asking why a map body's values are typed beside a `query`
    parameter, or changing how the planner types a body that declares no property.
tags: [decisions, code-generation, openapi]
---

# ADR 0015: The values of a map body are typed by their schema, beside a query parameter too

> **In brief**
>
> - The input DTO of a body that declares no `properties` has one property, `additionalProperties`, typed
>   `array<array-key, T>` and never `Optional`. `T` is the value schema's type, whether or not a `query` parameter sits
>   beside the body: the request judges the values there too.
> - The `mixed` type beside a `query` parameter, and the pass that found those bodies before any class was registered,
>   are gone: nothing is left that the request leaves unchecked at the root.
> - The request side now types a map the way the response side does
>   ([ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md)).
> - This supersedes [ADR 0004](./0004-carry-a-map-body-under-additionalproperties.md), and restates what of it still
>   holds.

**Status:** Accepted · **Card:** [#169](https://github.com/Gcob/lara-spec-first/issues/169) · **Pull request:**
[#194](https://github.com/Gcob/lara-spec-first/pull/194)

## Context

[ADR 0004](./0004-carry-a-map-body-under-additionalproperties.md) gave a body that declares no property one DTO property
holding its keys. It typed that property by the value schema only where the request judged the values, and `mixed` where
it did not, because a root map beside a `query` parameter was reported and not validated: the validated data holds the
body and the query string, and a plain wildcard would have held the parameter's key to the value schema. A DTO that
typed those values would have claimed what nothing checked. One class serves every operation that reaches a named
schema, so the planner decided it before registering any class, with a pass over every request.

[ADR 0014](./0014-judge-undeclared-keys-by-name.md) changes the premise. The root's guard spares the keys the `query`
parameters arrive under, so the request judges the values of a root map beside a parameter, and the parameter keeps its
own rules. Nothing at the root is left unchecked by the request, and the response side already types a map by its values
whatever sits beside it ([ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md)).

## Decision

**The values are typed by their schema, always.** A map of integers beside `?dryRun=` is `array<array-key, int>`, a map
of `$ref` values holds the value class (`PetInputDto` for `Pet`), an inline object value is a class of its own named
after the property with `Value`, and an open object or an empty value schema stays `array<array-key, mixed>`, since the
request judges nothing about its values. The class a named schema gets does not depend on the operation that reaches it,
so the classes are the same in every order the document writes its operations in.

**The pass that decided `mixed` goes, and so does its finding.** The planner no longer records the bodies whose values
the request leaves unchecked, and a file no longer says "the request does not validate the values of this body". The
predicate the rule set and the planner shared is gone with them, since there is nothing left to ask.

**An object that declares properties beside a value schema still carries its declared properties only,** with a finding
on its class that says the values of the other keys are not read there, though the request may validate them and
`validated()` holds them. Carrying those values is a decision of its own.

### What of ADR 0004 still holds

The DTO has one property, `additionalProperties`, holding the body's keys as sent, never `Optional` (an absent or empty
body is `[]`), the same on the partial class. `from()` hands the whole payload to the property, converting each value
the way a map property converts its values, and `toArray()` returns it. The key type is `array-key`, since PHP turns a
numeric key into an `int`. `validated()` holds every key of the body: the root gets `'*' => ['sometimes']` where no
value rule is emitted, so the wildcard keeps each key without asking anything of it. `dto()` leaves the `query`
parameters' keys out of the data it hands the DTO, a map key named like a `query` parameter is read from the query
string, and a key a form delivers under a rewritten name is carried under that name, each with a finding. A map keyed by
IDs keeps its keys, since the rules are written again under each integer-like key. One class serves every operation that
reaches a named schema, and it is registered once, whichever operation comes first.

## Alternatives turned down

- **Keep the empty DTO.** It carries nothing, and says so in a finding. A DTO that cannot hold the body is a poor answer
  to "the payload arrives as a DTO".
- **A property per key.** A map has no declared key, so there is no key to name.
- **Patch the class when a later operation leaves the values unchecked.** There is no such operation now, and with a
  `$ref` value the first operation may already have registered a class nothing would read. The generated tree would
  depend on the order of the document.
- **Keep `mixed` beside a `query` parameter for the sake of the old type.** Nothing checks less there than anywhere
  else, so `mixed` would hide the type the request guarantees, and the request side would type a map differently from
  the response side for no reason.

## Consequences

- **A DTO of a map body beside a `query` parameter changes type:** `array<array-key, mixed>` becomes
  `array<array-key, T>`, and a map of objects there now has a value class. A controller that read the values as `mixed`
  reads them as `T`, and a form map's scalars are cast, as everywhere else.
- **A map body's DTO is the same shape wherever it sits,** on the request side and on the response side.
- **This does not settle carrying the values of an object that declares properties.**
