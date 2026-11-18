---
title: "ADR 0013: A response DTO mirrors its schema, and a schema it cannot type costs its type, not the build"
audience: Users and contributors
covers: >
    The decision that `spec:build` writes one response DTO per documented response shape, named after its schema without
    `Input`, with an optional property typed `Optional|T`, a `writeOnly` property left out, a `date-time` written as
    Laravel writes it, nothing cast on the way in, nested DTOs and every map written to the encoder as JSON objects, and
    an object with no `properties` carried whole; and that a response schema with a fault of its own (a name that leaves
    no class name, two keys that derive one property, `allOf` branches that disagree) gets no DTO while the build goes
    on. Which statuses get no DTO, and the alternatives turned down.
read_before: >
    Reading a response DTO in a controller or a factory, asking why an optional response property is `Optional`, why a
    response DTO casts nothing, why a status has no DTO or a property is `mixed`, or changing how the planner names,
    types or writes a response type.
tags: [decisions, code-generation, openapi]
---

# ADR 0013: A response DTO mirrors its schema, and a schema it cannot type costs its type, not the build

> **In brief**
>
> - A response DTO is the response schema as a PHP type: named after the schema with no `Input`, one promoted property
>   per key, `Optional|T` for a key the contract leaves out of `required`, no property for a `writeOnly` key.
> - It casts nothing, so a value of the wrong type is a `TypeError` naming the property. It writes a `date-time` the way
>   Laravel's `toJSON()` does, and hands nested DTOs and every map to the JSON encoder as objects, so an empty map is
>   `{}`.
> - A status gets a DTO when it has a JSON body that is an object or a list of objects. No body, no JSON media type, or
>   two JSON schemas under one status: no DTO, and the generated controller says why. The build is not refused.
> - A schema with a fault of its own, a name that leaves no class name, two keys that derive one property or `allOf`
>   branches that disagree, gets no DTO either: its status says why and how to fix it, and a property that held it is
>   `mixed`. A response enforces nothing, so the build goes on.
> - Turned down: refusing the build for such a schema, picking a branch of an `allOf`, a half-built DTO, plain `T` for
>   an optional property, `toRfc3339String()`, an empty DTO for a map, a DTO that is `Responsable` or an API Resource,
>   and casting in `from()`.

**Status:** Accepted · **Card:** [#178](https://github.com/Gcob/lara-spec-first/issues/178) · **Pull request:** its pull
request

## Context

A request body already became a typed input DTO. A response was read by nothing, until
[ADR 0011](./0011-mirror-a-response-schema-in-its-dto.md) gave each response shape a DTO. That ADR made the response
side meet the refusals the input side already made, and the result was a build that stopped on a document `0.2.0`, which
read no response, built without a word.

Five facts shape what a response DTO can be, and what a fault in one costs:

- **Nothing has checked the value before `from()` sees it.** A request is validated first, and its DTO casts what
  validation accepted. A response is built from a model, where `(int) 'abc'` is `0` and `(bool) 'false'` is `true`.
- **One schema is routinely returned by operations that answer `200` and `201`.** The status belongs to the operation,
  and a class shared by both cannot decide it.
- **A DTO is a promise about a JSON document.** PHP's `json_encode()` writes an empty array as `[]` and a list-shaped
  array as `["a","b"]`, while the contract said object. A type that is right in PHP can still send the wrong JSON.
- **A request refuses a fault because a rule set built from half a contract enforces half of it.** A response has no
  rule set: nothing validates it, so a fault in its schema costs the type that mirrors the schema and nothing that runs.
- **A response and a request can derive one class name.** A response schema `UserInput` gives `UserInputDto`, which is
  also what a request schema `User` gives.

## Decision

**Names.** A response DTO is named after the schema it describes, without `Input`: `$ref: User` gives `UserDto`, and a
schema reached from another file gives the same name. A schema written inline takes the operation's name and the status,
`CreateUser201ResponseDto`, `CreateUser2XXResponseDto`, `CreateUserDefaultResponseDto`. An object nested inline adds its
property (`CreateUser201ResponseAddressDto`), an element of an array adds `Item` and a value of a map adds `Value`, as
the request side does. Two different schemas that give one class, or a response class that an input DTO already has, are
a build error naming both positions, never a silent suffix.

**Which statuses get a DTO.** Every declared status (`200`, `2XX`, `default`, the `4xx` and `5xx` ones) whose content
has a JSON media type, `application/json` or any `+json`, with a schema that is an object or a list of objects. A list
has no wrapper class: its items get the DTO. These get none, and the generated controller names the status and says why:

- no body, as a `204`;
- no JSON media type, as `text/csv` alone;
- two JSON media types under one status with schemas that are not the same shape. With the same schema it is one DTO;
- a body that is a list with no `items`, a list of values that are not objects, or neither an object nor a list;
- a schema with a fault of its own, below.

**A schema with a fault of its own gets no DTO, and the build goes on.** Three faults count: a schema name that leaves
no class name once it is turned into one, two keys of one schema that derive one property name, and `allOf` branches
that disagree, so there is no one schema to read. The status says which, in words for the reader of the controller and
with the fix: "its keys `user-id` and `user_id` both derive the property `$user_id`. Rename one of them." A property
that would have held the schema is `mixed`, `Optional|mixed` when optional, and the DTO that holds it says the same in
its findings after "`address` is `mixed`:". Everything else in that DTO keeps its type.

- **The innermost value that cannot be typed is the `mixed` one.** A list whose items disagree is `list<mixed>`, and a
  map whose values disagree is a map of `mixed`, as an item with no single type already is. One rule for every value a
  DTO cannot type.
- **A schema with such a fault has no class at all, never half of one.** Its own faults are found before it has a
  registry entry, so a type that recurses back to it finds no class it will not have, and no file points at a class that
  was not written. A fault in one property costs that property only, and a property whose own `allOf` disagrees is
  `mixed` while its sibling keys keep their types.
- **A schema whose `allOf` cannot be merged, or whose keys collide, gets no DTO as a whole**, even when one property is
  the cause: the merge reads the whole object at once and has no partial result, and a DTO that dropped one of two
  colliding keys would say a property is absent that the contract has.
- **A name that would be a DTO and leaves no class name is said to have no class name first**, ahead of branches that
  disagree: the name is the first thing a DTO needs, and the rename that gives it one comes before the rest.
- **Each of these is recorded in the plan under a cause of its own,** with where the type was lost and the schema that
  gave way, beside the sentence a person reads. A keyword a DTO cannot type (`oneOf`, a union of types, no type), a
  recursion left `mixed` and a status with no body are not faults of the schema, and carry no such record.

**An optional property is `Optional|T`.** A key left out of `required` is `Optional|T $x = new Optional`, required
properties come first and then the optional ones, each group in the contract's order. `Optional` is the class the input
side already uses. A DTO can then be built with every optional property absent, and `toArray()` leaves them out.

**A `writeOnly` property has no property** in the response DTO, and the file's findings name it: a response never sends
it. A `readOnly` property is read like any other.

**Nothing is cast.** `from()` takes an array and reads each value as it is. Every generated file declares
`strict_types`, so a scalar of the wrong type is a `TypeError` naming the property. A list's elements and a map's values
are not checked one by one, since PHP names a property in that error and never an element. A `date-time` is read with
`CarbonImmutable::parse()`.

**A `date-time` is written as Laravel's `toJSON()` writes it**: UTC, a `Z`, microseconds, so
`2026-11-14T10:00:00.123456-05:00` goes out as `2026-11-14T15:00:00.123456Z`. A `date` is written with `toDateString()`.

**`jsonSerialize()` returns an object.** Nested DTOs reach the encoder as DTOs, so they serialize the same way, and
every map is written as a JSON object: an empty map is `{}` and a map keyed `"0"` and `"1"` is `{"0":"a","1":"b"}`,
never `[]` or `["a","b"]`. An object whose optional properties are all absent is `{}`. **`toArray()` stays a plain PHP
array** at every depth, which is what mass assignment and the rest of PHP expect.

**A response DTO is `JsonSerializable` and not `Arrayable`.** Laravel calls `toArray()` on an `Arrayable` before it
encodes one: `response($dto, 201)`, a paginator and `Collection::toArray()` all do, and `toArray()` writes `[]` for an
empty map. Without the interface, every path reaches `jsonSerialize()` and writes `{}`, which is how Laravel's own
`JsonResource` works. The cost is that code typed against `Arrayable` cannot take a response DTO, and no generated code
does.

**An object with no `properties` is carried whole.** It has no declared key for a property to be, so its DTO has one
property, `additionalProperties`, typed `array<array-key, T>` and never `Optional`, as
[ADR 0004](./0004-carry-a-map-body-under-additionalproperties.md) does for a request body. `T` is what the value schema
becomes, and `mixed` for an open object. A response has no `query` parameter, so the values are always typed. A root map
goes out as the map itself, never as `{"additionalProperties": …}`. Beside declared `properties`, an
`additionalProperties` schema is not carried: the DTO has the declared properties only, and the file's findings say the
other values are not read.

**A nullable root keeps its DTO.** A response of `type: [object, 'null']` gets the DTO of the object, and the
controller's finding for that status ends "or `null`, which no DTO holds".

## Alternatives turned down

- **Refusing the build for a schema with a fault of its own.** It is what a request does, and for a request it is right.
  For a response it refuses a document that built on `0.2.0` for a type the generated code does not need: nothing
  validates a response, so the refusal protects nothing. A status with no DTO and a sentence costs the reader a type,
  not the build.
- **Picking a branch of an `allOf` that disagrees.** Either choice describes half of what the document wrote and drops
  the other half in silence, and the DTO would then be a promise the contract did not make.
- **A DTO with the properties that are not at fault.** `allOf` merges the whole object at once and has no partial
  result, and a DTO that dropped one of two colliding keys would say a property is absent that the contract has.
- **Plain `T` for an optional property.** It is stricter than the contract, since a server may omit a key the contract
  allows it to omit, and it would need a finding in every file. It spares a factory from writing `new Optional`, which
  is a small gain against a type that says less than the contract does.
- **`toRfc3339String()` for a `date-time`.** It drops the fraction. `toJSON()` keeps what Carbon holds, and it is what
  Laravel writes when it serializes a model, so a response built from a model and one built from a DTO read the same.
- **An empty DTO for a map.** It carries nothing, so a controller returning it sends `{}` whatever the map held. A map
  response is data, and its keys are the data.
- **A build error for two JSON schemas under one status.** It would refuse a contract that builds today, for a response
  the generated code does not need a type for. A status with no DTO and a finding costs the reader a type, not the
  build.
- **A DTO that is `Responsable` or an API Resource.** The status code and the headers belong to the operation, and one
  schema is returned under several statuses. An API Resource is built from a model by behavior that this DTO leaves to a
  factory.
- **Casting in `from()`.** A cast turns a wrong value into a different one, silently, where a `TypeError` names the
  property. The contract is what a response has to match, and a cast would hide that it did not.

## Consequences

- **A contract change to a response is a changed constructor.** Code that reads a property the contract removed fails
  static analysis, and a test runs PHPStan over such a reader to prove it.
- **A contract that builds on `0.2.0` still builds when a response schema has a fault of its own.** The generated
  controller and DTO say where, and the fix is in the contract. A name two response schemas share, or one an input DTO
  already has, still stops the build.
- **A `mixed` property can be a fault and not a keyword.** The file's finding says which, and the plan records the
  faults under their own causes.
- **A controller sees the `null` of a nullable response only in its own finding.** The DTO does not say a status may
  answer `null`, because one DTO serves every operation that returns the schema, and not all of them may.
- **What builds a response DTO from a model is not settled here.** That is the factory's, in
  [`response-dtos.md`](../../guide/code-generation/response-dtos.md#factories-carry-the-behavior).
