---
title: "ADR 0010: `dto()` returns `null` for a `null` body, wherever a JSON body allows one"
audience: Users and contributors
covers: >
    The decision that `dto()` is typed `?XInputDto` and returns `null` when the body's schema allows `null` and the
    client sends a JSON `null`, on every operation that has such a body and not only where `null` newly passes, how that
    differs from an absent body, and the alternatives turned down.
read_before: >
    Calling `dto()` in a controller whose JSON body allows `null`, asking why static analysis wants a null check on it,
    changing when a generated request lets a JSON `null` through, or deciding what a CRUD method does with a `null`
    body.
tags: [decisions, code-generation, compatibility]
---

# ADR 0010: `dto()` returns `null` for a `null` body, wherever a JSON body allows one

> **In brief**
>
> - When a body's schema allows `null` and the body is read as JSON, `dto()` is typed `?XInputDto` and returns `null`
>   when the client sends the JSON `null`. It holds for an optional body, a `PATCH`, a required body that requires
>   nothing and a required body that requires a property, so one rule covers every such operation.
> - An absent optional body is not `null`: it has no content, passes, and keeps the DTO of `Optional`s.
> - A body that cannot be `null`, or a nullable one read only as a form, keeps the plain return type.
> - It changed the return type of operations that worked before, so a controller that calls `dto()->toArray()` there
>   needs a null check.
> - Turned down: returning `null` only where `null` newly passes, and keeping the DTO of `Optional`s for a `null` body.

**Status:** Accepted · **Card:** [#111](https://github.com/Gcob/lara-spec-first/issues/111) · **Pull request:**
[#156](https://github.com/Gcob/lara-spec-first/pull/156)

## Context

A body whose schema is `type: [object, 'null']` allows the JSON `null`. Laravel reads that content as `[]`, the same as
`{}`, so the validated data of a `null` body is an empty array.

`dto()` builds the body's input DTO from the validated data. On three kinds of body the request already let a `null`
through: an optional body, a `PATCH`, and a required body that requires nothing. There `dto()` built the DTO from `[]`:
every property `Optional`, which is what an object sent as `{}` gives.

A required body that requires a property refused `null` with a `422` on each required key, though the contract allows
it. Letting it through (see
[a nullable body, `null` or whole](../../guide/code-generation/request-validation.md#a-nullable-body-null-or-whole))
meant `dto()` could be asked to build the full DTO, which reads each required key as `$payload['street']`, from `[]`: a
missing key, a `500` on a payload the contract allows. On that body `dto()` had to return `null`.

That left a question: whether the operations where `null` already passed follow, or keep the DTO of `Optional`s.

## Decision

**`dto()` is typed `?XInputDto` and returns `null` when the body's schema allows `null`, the body is read as JSON and
the content is the JSON `null`.** Read as JSON means JSON alone or JSON beside a form. The generated `dto()` starts with
`if ($this->isJson() && trim($this->getContent()) === 'null') { return null; }`, and its type is nullable under the
condition the request uses to let a JSON `null` through (a nullable root, read as JSON), so the type and the check
cannot drift apart. The rule reads straight from the contract: `null` allowed, JSON, so `?X`. It holds for:

- an optional body;
- a `PATCH`, whose DTO is the partial one;
- a required body that requires nothing;
- a required body that requires a property.

**An absent optional body is not `null`.** It has no content, so it passes and `dto()` keeps the DTO of `Optional`s,
which tells the controller the client sent nothing. `{}` gets the same DTO. A controller can tell "the client sent
`null`" from "an object with nothing in it" from "no body".

**A body that cannot be `null` keeps the plain return type, and so does a nullable one read only as a form.** A form
cannot send `null`.

The generated file tells the reader too: the finding it carries about `dto()` adds "or `null` when the body is `null`"
where the type is nullable.

## Alternatives turned down

- **Return `null` only where `null` newly passes**, which is a required body that requires a property. No code that runs
  today would change. But a `null` body would mean two things, a DTO of `Optional`s on most operations and `null` on
  one, and the `?` would depend on a condition (a required body that requires a property) that a reader cannot guess
  from the contract, where "`null` is allowed" is what the contract says.
- **Keep the DTO of `Optional`s for a `null` body.** It says "an object with nothing in it", which is not what the
  client sent. On a body that requires a property it cannot be kept at all: the full DTO would read a missing key, a
  `500`.

## Consequences

- **A controller handles `null`.** On an operation whose JSON body allows `null`, `$request->dto()->toArray()` fails at
  runtime when a client sends `null`, and static analysis asks for the null check: `$request->dto()?->toArray()`. The
  change was breaking for operations that worked before, because the return type changed on them.
- **`null` and absent stay two states.** `null` is the payload the contract allows, and absent is no payload.
- **What a CRUD method does with a `null` body is left open.** The CRUD seam of
  [the controllers guide](../../guide/controllers.md#what-the-generated-controller-contains) hands a method the
  generated DTO, and `null` has none to hand. [#95](https://github.com/Gcob/lara-spec-first/issues/95) is where that is
  settled.
