---
title: "ADR 0011: A response DTO mirrors its schema, and writes back what the contract says"
audience: Users and contributors
covers: >
    The decision that `spec:build` writes one response DTO per documented response shape, named after its schema without
    `Input`, with an optional property typed `Optional|T`, a `writeOnly` property left out, a `date-time` written as
    Laravel writes it, nothing cast on the way in, nested DTOs and every map written to the encoder as JSON objects, and
    an object with no `properties` carried whole. Which statuses get no DTO, and the alternatives turned down.
read_before: >
    Reading a response DTO in a controller or a factory, asking why an optional response property is `Optional`, why a
    response DTO casts nothing, why a status has no DTO, or changing how the planner names, types or writes a response
    type.
tags: [decisions, code-generation, openapi]
---

# ADR 0011: A response DTO mirrors its schema, and writes back what the contract says

> **In brief**
>
> - A response DTO is the response schema as a PHP type: named after the schema with no `Input`, one promoted property
>   per key, `Optional|T` for a key the contract leaves out of `required`, no property for a `writeOnly` key.
> - It casts nothing, so a value of the wrong type is a `TypeError` naming the property. It writes a `date-time` the way
>   Laravel's `toJSON()` does, and hands nested DTOs and every map to the JSON encoder as objects, so an empty map is
>   `{}`.
> - A status gets a DTO when it has a JSON body that is an object or a list of objects. No body, no JSON media type, or
>   two JSON schemas under one status: no DTO, and the generated controller says why. The build is not refused.
> - An object with no `properties` is carried whole under one property, `additionalProperties`, as a request body is.
> - Turned down: plain `T` for an optional property, `toRfc3339String()`, an empty DTO for a map, a build error for two
>   JSON schemas under one status, a DTO that is `Responsable` or an API Resource, and casting in `from()`.

**Status:** Superseded by [ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md) · **Card:**
[#39](https://github.com/Gcob/lara-spec-first/issues/39) · **Pull request:**
[#176](https://github.com/Gcob/lara-spec-first/pull/176)

## Context

A request body already became a typed input DTO. A response was read by nothing: the generated controller said so, and a
change to a response schema broke no line of the code that built or read the response.

Four facts shape what a response DTO can be:

- **Nothing has checked the value before `from()` sees it.** A request is validated first, and its DTO casts what
  validation accepted. A response is built from a model, where `(int) 'abc'` is `0` and `(bool) 'false'` is `true`.
- **One schema is routinely returned by operations that answer `200` and `201`.** The status belongs to the operation,
  and a class shared by both cannot decide it.
- **A DTO is a promise about a JSON document.** PHP's `json_encode()` writes an empty array as `[]` and a list-shaped
  array as `["a","b"]`, while the contract said object. A type that is right in PHP can still send the wrong JSON.
- **A response and a request can derive one class name.** A response schema `UserInput` gives `UserInputDto`, which is
  also what a request schema `User` gives.

## Decision

**Names.** A response DTO is named after the schema it describes, without `Input`: `$ref: User` gives `UserDto`, and a
schema reached from another file gives the same name. A schema written inline takes the operation's name and the status,
`CreateUser201ResponseDto`, `CreateUser2XXResponseDto`, `CreateUserDefaultResponseDto`. An object nested inline adds its
property (`CreateUser201ResponseAddressDto`), an element of an array adds `Item` and a value of a map adds `Value`, as
the request side does. Two different schemas that give one class, or a response class that an input DTO already has, are
a build error naming both positions, never a silent suffix. So is a component name that leaves no class name once it is
turned into one, and two keys that derive one property.

**Which statuses get a DTO.** Every declared status (`200`, `2XX`, `default`, the `4xx` and `5xx` ones) whose content
has a JSON media type, `application/json` or any `+json`, with a schema that is an object or a list of objects. A list
has no wrapper class: its items get the DTO. These get none, and the generated controller names the status and says why:

- no body, as a `204`;
- no JSON media type, as `text/csv` alone;
- two JSON media types under one status with schemas that are not the same shape. With the same schema it is one DTO;
- a body that is a list with no `items`, a list of values that are not objects, or neither an object nor a list.

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
- **A contract that builds on `0.2.0` can stop building.** The response side meets the refusals the input side already
  made: a name an input DTO takes, a component name that leaves no class name, two keys that derive one property, and
  `allOf` branches that disagree. It is a breaking change, and the changelog says how to fix each.
- **A controller sees the `null` of a nullable response only in its own finding.** The DTO does not say a status may
  answer `null`, because one DTO serves every operation that returns the schema, and not all of them may.
- **What builds a response DTO from a model is not settled here.** That is the factory's, in
  [`response-dtos.md`](../../guide/code-generation/response-dtos.md#factories-carry-the-behavior).
- **The keywords a response schema writes are not named by `spec:doctor` yet.** The doctor reports responses as one
  `Deferred` count, so a keyword that gives a property no single type (`oneOf`, a type union) is a `mixed` property with
  a finding in the file, and nothing else says it.
