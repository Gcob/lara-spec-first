---
title: "ADR 0004: A map or open-object body is carried whole, under `additionalProperties`"
audience: Users and contributors
covers: >
    The decision to give the input DTO of a body that declares no property one property, `additionalProperties`, typed
    `array<array-key, T>`, typed `mixed` wherever the request does not validate the values, with `validated()` holding
    every key of such a body, and the alternatives turned down.
read_before: >
    Reading `$dto->additionalProperties` in a controller, asking why a map body's values are `mixed` beside a `query`
    parameter, or changing how the planner types a body that declares no property.
tags: [decisions, code-generation, openapi]
---

# ADR 0004: A map or open-object body is carried whole, under `additionalProperties`

> **In brief**
>
> - The input DTO of a body that declares no `properties` has one property, `additionalProperties`, typed
>   `array<array-key, T>` and never `Optional`. `validated()` holds every key of such a body.
> - `T` is the value schema's type where the request validates the values, and `mixed` where it does not (an open
>   object, or a map beside a `query` parameter). One class serves every operation that reaches a schema, so that is
>   decided before any class is registered.
> - Turned down: an empty DTO that carries nothing, a property per key, and patching a class when a later operation
>   arrives.

**Status:** Accepted · **Card:** [#109](https://github.com/Gcob/lara-spec-first/issues/109) · **Pull request:**
[#170](https://github.com/Gcob/lara-spec-first/pull/170)

## Context

A body that is a map (`additionalProperties: {type: integer}`) or an open object (`type: object` and nothing else) has
no declared key. Its keys are the data. The input DTO is a class of declared properties, so until now such a body got a
class with none, and the generated file told the reader to read the body from the request. The DTO carried nothing, and
a controller that passed `$request->validated()` to `create()` passed `[]`: Laravel's `validated()` keeps a key only
when a rule names it, and no rule named any.

Three facts decide what can be promised:

- **One class serves every operation that reaches a named schema.** `Labels` used by `PUT /a` and by `PATCH /b` is one
  `LabelsInputDto` and one `LabelsPartialInputDto`. Whether its values are typed cannot depend on which operation the
  build met first.
- **A root map's values are validated only where no `query` parameter shares the data**
  ([ADR 0003](./0003-validate-each-value-of-a-map.md)). Beside one, the request judges no value, and a DTO that typed
  them would claim what nothing checked.
- **PHP turns a numeric key into an `int`.** A map keyed `"2024"` arrives with the key `2024`.

## Decision

**The DTO has one property, `additionalProperties`, holding the body's keys as sent.** It is typed
`array<array-key, T>`, never `Optional` (an absent or empty body is `[]`), and the partial type is the same, since no
key of it can be absent. `from()` hands the whole payload to the property, converting each value the way a map property
converts its values, and `toArray()` returns it. The key type is `array-key` because of the numeric keys above.

```php
public function __construct(
    public array $additionalProperties,
) {}
```

**`T` is `mixed` wherever the request does not validate the values.** An open object has no value schema, an empty value
schema allows anything, and a root map beside a `query` parameter is reported and not validated. The DTO never types
what the request did not check, which is the rule the planner already follows for a recursion it did not unroll. Both
the predicate and its answer are shared: the planner asks `RuleSetBuilder::validatesRootMapValues()`, the same function
the rule set asks, so the two cannot disagree.

**That is decided before any class is registered.** The planner records, in a pass over every request ahead of the walk,
the position of each body whose operation leaves its values unvalidated: the component's position for a named body, and
the operation's own request body for an inline one. A class registered at one of those positions types its values
`mixed`, for the full and the partial class alike, and never walks the value schema, so no class is written for a value
nothing reads. The classes are the same in every order the operations are written in. An inline body is a class per
operation, named after its request, so only the one beside the `query` parameter goes `mixed`.

**`validated()` holds every key of the body.** The root gets `'*' => ['sometimes']` where no value rule is emitted,
after every explicit key, so the wildcard keeps each key without asking anything of it. A map whose values are validated
already has its wildcard.

**`dto()` leaves the `query` parameters' keys out of the data it hands the DTO.** `validated()` holds the body's keys
and the query string's, and the DTO is the body's. Two findings are written for such a body: a map key named like a
`query` parameter is read from the query string, never from the body, and a key a form delivers under a rewritten name
(`a.b` as `a_b`) is carried under that name.

## Alternatives turned down

- **Keep the empty DTO.** It carries nothing, and says so in a finding. It is the simplest, and it leaves a map body, a
  common shape for a webhook or a bag of settings, with a typed class that is no help. A DTO that cannot hold the body
  is a poor answer to "the payload arrives as a DTO".
- **A property per key.** A map has no declared key, so there is no key to name.
- **Patch the class when a later operation leaves the values unvalidated,** as the planner does for a property a form
  cannot check. With a `$ref` value, the first operation may already have registered the value's class, which nothing
  then reads, and the partial class would not be patched in the same pass. The generated tree would depend on the order
  of the document.
- **Type the values by the schema and leave the request to disagree,** beside a `query` parameter. A typed
  `array<array-key, PetInputDto>` built from values nothing validated is a `TypeError` waiting for a client.

## Consequences

- **A controller reads `$dto->additionalProperties`** where it read the request for the raw body, and `validated()` is
  the body where it was `[]`. A caller that relied on the empty DTO, or on `validated()` being empty for such a body,
  changes.
- **Beside a `query` parameter, the values are `mixed`,** until a card validates them there.
- **A map keyed by IDs keeps its keys.** Laravel renumbers an integer-like top-level key when it expands the wildcard
  rule, so the generated `rules()` writes each rule under `*` again under every integer-like key the request carries.
  The cost is a `rules()` that reads the body at request time for a root map.
- **A body key named like a `query` parameter is the parameter's,** and the findings say so.
- **This does not settle values beside declared `properties`,** which stay reported as ADR 0003 says.
