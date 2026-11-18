---
title: "ADR 0017: routeAction returns the response DTO, and the generated parent sends the status the contract declares"
audience: Users and contributors
covers: >
    The decision that a generated `routeAction` declares what the operation's success statuses carry (a DTO, `array` for
    a list, `null` where the body may be, a union when several statuses have a DTO) plus `JsonResponse` when there are
    several success statuses and Symfony's `Response` when the contract declares an answer that is not a JSON body, that
    an error status leaves a controller as an exception, that the generated parent's `callAction()` sends the status the
    contract declares through the shipped `SuccessStatus` class whose name a generated file freezes, which returned
    values get a status and which pass to Laravel untouched, that `spec:make` writes the parent's return type from the
    build plan, that `spec:build` names a custom controller whose return type no longer fits, and the alternatives
    turned down.
read_before: >
    Writing or reviewing a custom controller's `routeAction`, asking why a generated controller returns a DTO and not
    `mixed`, why a `201` needs no `response()->json()`, why the parent defines `callAction()`, why a `204` or a redirect
    widens the return type, what a child that returns `mixed` gets from `spec:build`, or changing the return type or the
    status a generated controller declares.
tags: [decisions, code-generation, openapi, laravel, versions]
---

# ADR 0017: routeAction returns the response DTO, and the generated parent sends the status the contract declares

> **In brief**
>
> - A generated `routeAction` declares what the operation's success statuses carry: the DTO of each status that has one,
>   `array` for a list (its items in a `@return`), `null` where the body may be `null`, and the union of them when
>   several statuses have a DTO. No success status with a DTO: `mixed`, as before.
> - Several success statuses add `Illuminate\Http\JsonResponse`, and a contract that declares an answer that is not a
>   JSON body (a `204`, a `text/csv` body, a redirect) adds Symfony's `Response`. **An error status is never part of the
>   type:** it is thrown (`abort()` or an exception), and the DTO of an error response is for the exception handler.
> - The generated parent defines `callAction()`, which Laravel's dispatcher calls, and it sends the status the contract
>   declares through `SuccessStatus`. A child that overrides `routeAction` and never calls `parent::` still gets it.
> - `spec:make` writes the same return type from the build plan, and `spec:build` names a custom controller whose return
>   type PHP refuses without resolving a class (none, `mixed` or `array` under a DTO).
> - Breaking, in a `0.x`: every scaffolded child declared `: mixed`. Turned down: error DTOs in the type, the status
>   written by the child, and the status logic copied into every parent.

**Status:** Accepted · **Card:** [#165](https://github.com/Gcob/lara-spec-first/issues/165) · **Pull request:**
[#200](https://github.com/Gcob/lara-spec-first/pull/200)

## Context

`routeAction` returned `mixed`. [ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md) gave every documented
response a DTO, so the parent could name the type a controller answers with, and the one contract that binds a custom
controller, "PHP already enforces that", was the least useful one: anything returned compiled. A controller that
returned the wrong shape failed in a client, not in the editor or in PHPStan.

The status had the same gap. A contract that declares `201` for an operation said so in the generated file and nowhere
else: the child had to remember `response()->json($dto, 201)`, and a child that returned the DTO plainly sent `200`.
Narrowing the return type later is breaking (every scaffolded child declared `: mixed`), so it costs a CHANGELOG entry
in `0.x` and a major after 1.0.

## Decision

**The return type is what the success statuses carry, read from the build plan and never derived again.** A success
status is any `2xx` or `2XX` one, and `default` only when no `2xx` is declared. Each status with a DTO adds a member, in
contract order and each once: the DTO, `array` for a list, with the item type in a `@return` docblock (`list<UserDto>`),
and `null` last, written `?UserDto` when it is the only other member. A status with no DTO (a `204`, no JSON media type,
two JSON schemas, a fallback) adds none, and an operation with none stays `mixed`.

**Two response classes can join the type, and only where the contract needs them:**

- **`Illuminate\Http\JsonResponse`** when the operation declares several success statuses, since a response object
  carrying its own status is how a controller sends the one it picked.
- **Symfony's `Response`** when a success status has no JSON body (no content, or another media type beside the JSON
  one), or the contract declares a `3xx` or `3XX` beside a DTO status. It is the base of `noContent()` and
  `redirect()`'s answers, and it admits a `JsonResponse` too, so the two never appear together.

Each is imported by the file that names it. `JsonResponse` is what `response()->json()` returns, and `Response` is the
one base that `noContent()` and a redirect share.

**An error status leaves a controller as an exception.** The `4xx` and `5xx` DTOs never enter the type. Laravel's way is
`abort()` or an exception turned into a response by the handler, so a controller throws what the contract calls an
error, and the generated file says so in its findings.

**The generated parent's `callAction()` sends the status.** Laravel's `ControllerDispatcher` calls `callAction()` when a
controller has it, with what `routeAction` returns, so the parent writes one for each operation that declares a success
status and a child that overrides `routeAction` is not asked to call `parent::`. It is typed
`(string $method, array $parameters): mixed`, and it hands `routeAction`'s result and one table row per success status
to the shipped `Gcob\LaraSpecFirst\Http\SuccessStatus`, which does the choosing. The class is not public API, but a
generated file names it, so its name is frozen like `OperationNotImplementedException`, `Optional` and `NullOrAbsent`.

**Which values get a status, and which go to Laravel untouched:**

- **A status applies to what the contract can describe:** an instance of a DTO class of this operation's success
  statuses (compared by class, never by `JsonSerializable`), an array, a scalar and `null`. A DTO, an array or a scalar
  is written exactly as Laravel would write it, and only the number changes.
- **Everything else is Laravel's:** a Symfony `Response` of any kind (`response()->json($x, 202)`, `noContent()`, a
  redirect), a `Responsable`, a PSR-7 response, a `Model` and a DTO of an operation that does not declare it pass
  untouched, status included. A `Model` is `JsonSerializable` too, so a recently created one keeps the `201` Laravel
  gives it.
- **One success status:** it is the status of every value above.
- **Several:** a DTO answers the first status, in contract order, whose body is that class. An array answers the first
  list status whose items are the class of the first item; an empty array, or a first item no status matches, answers
  the first list status, and with no list status Laravel's own answer stands. `null` answers the first status whose body
  may be `null`, as JSON `null`, and Laravel's answer stands when none may. A scalar keeps Laravel's answer.
- **`null` is written `null`, never `{}`:** `new JsonResponse(null)` writes an empty object, so a status with a JSON
  body sends the literal, and a status with no body sends an empty one.
- **A `2XX` range or a lone `default` sends `200`,** the one number `callAction()` can choose for it.

**`spec:make` writes the parent's return type, read from the build plan.** The command keeps the plan it already makes
of the document before it writes anything, so a scaffold never derives a second type. The child declares the same type
and the same `@return`, and keeps its `parent::routeAction()` call, which answers `501` until the developer replaces it.

**`spec:build` names a custom controller whose return type no longer fits.** The reader that names a `routeAction` that
fell behind its parent's parameters also reads the return type, and speaks only where it is sure without resolving a
class: a child that declares none (under any parent, `mixed` included), or a built-in type the parent's does not hold
(`mixed`, `array` under a DTO, `null` beside one, `void`). It prints the type the parent declares, in full, so the line
loads as pasted. A child that declares a class is left to PHP and PHPStan, as before: an `array` where the guide used to
say a child may narrow `mixed` is the upgrade this catches.

**On Laravel 12 (CI from 12.55, [#188](https://github.com/Gcob/lara-spec-first/issues/188) settles the floor) and 13,**
the dispatcher calls `callAction()` the same way, and a test sends a request through the router to a parent and a child
that overrides `routeAction` without `parent::`. `SpecController` does not extend Laravel's `Controller` and defines no
`callAction()`, so nothing the parent defines shadows anything.

## Alternatives turned down

- **Keeping `mixed`.** It broke nothing, and it left the one binding contract unable to say what a controller returns.
  The cost of changing it only grows after 1.0.
- **Error DTOs in the return type.** A controller would return a `400` DTO instead of throwing, and every operation
  would carry a union of its errors. Laravel's handler already turns an exception into a response, and the DTO of an
  error is for it, so the type stays about what a success returns.
- **`JsonResponse` for every operation, or `Response` whenever several statuses exist.** It would widen the type of a
  contract that is all JSON and one status, and the DTO is what a client reads. A response class joins the type only
  where the contract needs a way to send something a DTO cannot be.
- **The child writing the status.** `response()->json($dto, 201)` in every child is the thing the contract was meant to
  remove, and a child that forgets it sends `200` for an operation that declares `201`.
- **The status in `SpecController`.** It holds no per-operation knowledge, and giving it a table would make the base
  class depend on every contract.
- **The child calling `parent::` for the status.** A child that replaces the scaffold's call to the parent, which is
  what implementing the operation means, would lose it. `callAction()` is what the dispatcher calls whatever the child
  does.
- **The choosing logic written into every parent.** It is some twenty-five lines that would be copied into each
  generated controller and tested once per copy. One class has one set of tests, and generated code already calls
  shipped classes.
- **A status for every returned object that is `JsonSerializable`.** A `Model` is one, and would lose Laravel's `201`.
- **Loading the custom child to check its return type.** A child that no longer fits is a fatal error at load, so
  loading it would end the build that is naming it. The reader judges the file's text and never resolves a class.

## Consequences

- **A child that declares `: mixed` under a typed parent must change it.** Every scaffolded child did, so every custom
  controller of an operation with a success DTO is affected, and `spec:build` names each one with the signature to
  paste. The CHANGELOG lists it as breaking.
- **The generated parent now defines `callAction()`.** A child that defines its own replaces the status. An operation
  with no success status in its contract gets none, and keeps Laravel's dispatch.
- **A returned array is matched on its first item.** A list of lists is matched by its class only, and an empty array
  has no class, so it takes the first list status. A child that wants another one returns `response()->json([], 202)`.
- **The return type follows the contract.** When a status stops carrying a DTO the parent's type narrows, and a child
  that still returns the dropped class is refused by PHP when the class loads, and by PHPStan before. A child that
  declares nothing, or a built-in type the parent does not hold, is what `spec:build` names.
- **A DTO is a type, not a check.** Whether a returned value is the one the contract describes is
  [#122](https://github.com/Gcob/lara-spec-first/issues/122)'s question, and nothing here validates a response.
- **`SuccessStatus` is frozen by name.** A generated file written before it changes keeps working, and a change to the
  table's shape is a breaking change to files a user has generated.
