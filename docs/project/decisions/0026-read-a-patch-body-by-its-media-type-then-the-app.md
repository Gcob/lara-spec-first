---
title: "ADR 0026: A `PATCH` body's required list is read by its media type, then by the app"
audience: Users and contributors
covers: >
    The decision that a body sent as `application/merge-patch+json` is read as a partial JSON document on every method,
    that the app's `requests.patch` key (`partial` or `as_written`) says how any other `PATCH` body is read, how one
    rule set and one input DTO answer the same question, what happens when a merge patch is declared beside another
    media type (over one schema, or over another), why `application/json-patch+json` stays unread, the cases the build
    and the doctor count, what stays open, and the alternatives turned down. ADR 0024 still holds for the default.
read_before: >
    Asking why a body sent as `application/merge-patch+json` has a generated request, why `PATCH` accepts a body with no
    required field by default, how to make a `PATCH` ask for what the schema requires, why a merge patch beside
    `application/json` counts as a gap on a `PUT`, why a nested object of a merge patch still asks for its required
    names, why a JSON Patch document answers 422, why there is no `x-partial` extension, or changing how a partial body
    is read.
tags: [decisions, code-generation, openapi]
---

# ADR 0026: A `PATCH` body's required list is read by its media type, then by the app

> **In brief**
>
> - OpenAPI gives `PATCH` no meaning, and `application/json` does not say whether a document is partial. The media type
>   answers first: a body sent as `application/merge-patch+json` (RFC 7396) is a partial JSON document, on any method,
>   and its required list is read as empty.
> - The app answers where the media type does not: `requests.patch` in `config/lara-spec-first.php` is `partial` (the
>   default, as a `PATCH` has always been read) or `as_written` (read as a `PUT` reads it). It applies to
>   `application/json` and to a form, never to a merge patch.
> - One flag, decided once in the rule set, tells the rules and the input DTO whether the body is partial, so the two
>   cannot disagree.
> - A merge patch declared beside another read media type over one schema is read by that other type, and a reading the
>   merge patch would not choose is a counted gap. Over another schema, the merge patch stays unread and counted.
>   `application/json-patch+json` stays unread, and a JSON Patch document answers `422` beside a JSON type.
> - Turned down: an `x-partial` extension, partial for both types when they disagree, branching the rules on
>   `Content-Type`, refusing the build over two schemas, reading JSON Patch, and a reading by method alone.
> - `0.2.0` let a merge patch through unchecked and without a generated request. It has both now, as the CHANGELOG says.

**Status:** Accepted · **Card:** [#217](https://github.com/Gcob/lara-spec-first/issues/217) · **Pull request:**
[#255](https://github.com/Gcob/lara-spec-first/pull/255)

## Context

A partial update is a real thing in HTTP, and the contract has three ways to name one, none of them settled by OpenAPI:

- **The method.** RFC 5789 defines `PATCH` as a set of changes to apply to a resource. OpenAPI gives the method no
  meaning of its own, and `requestBody` is read like any other.
- **`application/merge-patch+json`.** RFC 7396 defines a JSON document that is merged into the stored one: a key left
  out stays as it is, a nested object is merged, an array is replaced whole, and `null` removes a key. It is defined for
  the document, not the method, though `PATCH` is what it is "primarily intended for".
- **`application/json-patch+json`.** RFC 6902 defines a list of operations (`{"op": "remove", "path": "/a"}`), so its
  root is an array, and no field of it is a field of the resource.

[ADR 0024](./0024-hold-rules-and-report-every-gap.md) reads a `PATCH`'s required list as empty, by the method alone, and
counts that as information, since a partial update is what the method means. Three things were left over from it:

- **A merge patch was not read at all.** An operation that declared only `application/merge-patch+json` got no request
  class, no rules and no DTO, and `spec:doctor` counted the media type. Beside `application/json`, the build said the
  merge patch client "is judged by the rules of `application/json` on an empty body", which is false: Laravel reads any
  `+json` body as JSON, on its real content.
- **The method was the only switch.** An app that wants its `PATCH` operations to ask for what the schema requires had
  no way to say so, and a contract whose `application/json` `PATCH` is a full replacement in practice was read as
  partial.
- **The rules and the DTO read the method on their own.** Reading a merge patch in the rules only, on a `POST`, would
  answer `500` for `{}`: the rules let it through, and the full DTO's `from()` reads a required key that is not there.

## Decision

**The media type answers first.** `application/merge-patch+json` is a fourth media type the package reads, read as JSON.
A merge patch is partial on every method, since the document is what is partial and RFC 7396 does not tie it to `PATCH`:

- Every top-level property is `sometimes`, and `dto()` returns the partial type.
- The generated file says so as information, naming the media type and the required list it read as empty. No top-level
  property is counted, so the build warning and the doctor's exit code move only for a nested object that requires names
  (below).
- A value that is sent is judged as the schema says. `null` for a property the schema does not allow it for is refused
  (`{"name": null}` answers `422`), though RFC 7396 reads it as removing the key: the package reads `null` as a value,
  never as a removal.
- A merge patch on a `PUT` writes no sentence about an omitted field keeping its stored value, since a merge patch is
  not a replacement.
- The media type is matched as written, like the three the package already read: a `charset` parameter or another case
  is not folded.

**The app answers where the media type does not.** A `PATCH` read as `application/json` or as a form is partial or not
by `requests.patch`:

```php
'requests' => [
    'patch' => 'partial',
],
```

- **`partial`, the default.** The required list is read as empty, every property is `sometimes`, and `dto()` returns the
  partial type. This is the reading ADR 0024 records, so the default changes nothing for a contract that never declared
  a merge patch. The generated file's sentence now names the key and says `as_written` reads the list as written.
- **`as_written`.** The list is read as a `PUT` reads it: a `PATCH` that leaves out a required field answers `422`, and
  `dto()` returns the full type. Nothing is written about the list, since nothing is emptied.
- **It applies to a form as well as to JSON.** A form `PATCH` is partial today by the same method rule (Laravel's HTML
  forms send one through `_method`), and a key that governed JSON only would leave `as_written` half applied.
- **It never applies to a merge patch,** which is partial whatever the key says, and never to JSON Patch, which is not
  read.
- **A missing or `null` value is `partial`.** Any other value is refused with a message that names
  `lara-spec-first.requests.patch` and the two readings (`UnusableSettingException`), since a typo would otherwise
  quietly mean the default. `spec:build`, `spec:make` and `spec:doctor` read the key through the same `RuleSetOptions`
  object, as [ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md) does for its key: a doctor that
  read another value would call every request drifted, and with the key read, changing it without building again is
  exactly what drift reports.

**One flag, decided once, in the rule set.** `build()` computes whether the body is partial before it walks the body: a
merge patch read alone is, a `PATCH` is when `requests.patch` is `partial`, and anything else is not. The rules, the
`sometimes` on the root properties, the nullable required body, the form names and the sentences all read that flag, and
`RuleSet::$partialBody` carries it to the input DTO planner, which picks the partial or the full type from it and no
longer reads the method. The rules and the DTO answer one question in one place, and the `500` above cannot happen.

**Beside another read media type over one schema, the other one decides.** A merge patch declared with
`application/json` or a form over the same schema (`sameShapeAs()`) is one rule set, and one rule set cannot read a
required list two ways:

- If the other type reads the list as empty (`application/json` on a `PATCH` under `partial`), both are partial, and no
  top-level property is counted beyond the sentence the other type already writes (a nested object that requires names
  is counted, as for a merge patch alone).
- If it reads the list as written (a `POST` or a `PUT`, or a `PATCH` under `as_written`), the whole rule set does, and a
  merge patch that leaves a required name out answers `422`, though RFC 7396 lets it. That is a rule stricter than the
  contract for one of its clients, so it is counted and not left silent, as
  [ADR 0024](./0024-hold-rules-and-report-every-gap.md) asks: a gap with a `required` entry on the names the schema
  requires. The doctor exits `2` on it, and the remedy is to declare the merge patch alone, or, on a `PATCH`, to set
  `requests.patch` to `partial`.

**Over another schema, the merge patch is not read.** If a merge patch is declared beside another read media type over a
schema that is not the same shape, the package reads the other type, as it did before, and reports the merge patch like
any media type the rules do not come from (a counted `content` entry). `RuleSetBuilder::readMediaTypes()` is the one
place that decides which declared types a rule set reads, and the build, the DTO planner and the doctor all ask it, so
none of them reads the keywords of a schema the rules did not come from. A file part stays a file when
`multipart/form-data` is the type read.

**A nested object of a merge patch keeps its required list.** The partial reading is the root's. RFC 7396 merges a
nested object into the stored one, so `{"address": {"city": "Laval"}}` is valid, but the DTO has one type for a nested
object and its `from()` reads each required key directly, so the rules alone cannot read it as partial. A nested object
outside an array's items (an array is replaced whole, so an object in one is a complete value) keeps its
`required_array_keys` and is a counted gap, with a `required` entry on its names. It is not counted for an
`application/json` `PATCH`, where the nested list is the app's own reading, nor in the mixed case read as written, whose
root gap already covers every list. [#254](https://github.com/Gcob/lara-spec-first/issues/254) reads these objects as
partial.

**JSON Patch stays an unread media type, reported and not refused.** Its schema describes a list of operations, and the
package reads no body whose root is not an object, since a bare value or a list has no field for a rule. Reading it
would add nothing, and refusing the build would turn away the operation's other half and every other operation, which is
why the guide reports an unread media type. An operation that declares only `application/json-patch+json` gets no
request class, as before, and a counted `content` entry. Beside a read media type, the entry's sentence is now true:
Laravel reads every `+json` type as JSON, so a JSON Patch client is judged on its real body and not on an empty one.
Beside a JSON type a body that is not a JSON object answers `422` on `body` (a JSON Patch document is a list), and an
object is held to the rules of the type read. Beside a form only, the body is held to the form's rules. The generated
file and the doctor say the same sentence, for any unread `+json` type.

## Alternatives turned down

- **An `x-partial` extension on the operation.** The contract already has a standard, per-operation way to say partial:
  the merge patch media type. What it cannot say is one `application/json` operation read as written while the app's
  default is `partial`, and no one has reported needing it. An extension adds a package-only keyword for a case that has
  no demand, and adding it later breaks nothing. One key and one standard media type are the simpler contract.
- **Partial for both types when they disagree.** It would let the `application/json` client of a `POST` leave out a
  required field the contract requires, looser than the contract, which ADR 0024 forbids.
- **Branching the rules on `Content-Type`.** The rule set is read once from the contract and nothing rewrites it at run
  time, so one request would carry two sets of rules and every consumer of the rule set would have to know it.
- **Refusing the build when the types disagree, or when a merge patch sits over another schema.** It turns away a valid
  contract over an app setting, and the second case built before this record, with one counted gap. The gap is the
  honest answer: the request is stricter than the contract for one client, and it says so.
- **Refusing or reading JSON Patch.** Reading a list root is a feature of its own (validating a JSON Patch document),
  and refusing is heavier than the gap that already says the media type is not honored.
- **A reading by method alone.** It is the previous state: a merge patch with no request, and a `PATCH` that cannot be
  anything but partial. It is also how a merge patch on a `POST` would answer `500`, once only the rules knew about it.
- **A merge patch partial on a `PATCH` only.** RFC 7396 defines the document and not the method, so reading a `PUT`'s or
  a `POST`'s merge patch as written would be stricter than the media type the contract chose.
- **Reading `null` as removal.** It would add `nullable` to members the schema does not mark so, and an
  `Optional|T|null` to the partial DTO, for a reading no card has asked for: a contract that wants `null` to remove a
  key declares the property nullable.

## Consequences

- **A merge patch now has a generated request, a DTO and rules, and that breaks a custom controller.** The generated
  controller's `routeAction` gains the request parameter, which `spec:build` names, a client that sent a value the
  schema refuses now answers `422`, and `spec:doctor` exits `0` where it exited `2` for a flat merge patch. The
  CHANGELOG lists it as breaking. `requests.patch` breaks nothing, since its default is the previous reading.
- **ADR 0024 is not edited.** Its line that a `PATCH` reads the required list as empty stays true for the default, and
  the two new counted cases are gaps, which its rules already cover. This record adds the key and the media type to it.
- **The partial DTO's findings name a partial update and no longer the method,** so a generated input DTO changes by
  that line on the next `spec:build`, under either reading.
- **The generated request, the DTO and the doctor read the key together,** so a change to `requests.patch` takes another
  `spec:build`, and the doctor reports the generated requests as drifted until then.
- **A nested object of a merge patch is stricter than the contract, and counted.**
  [#254](https://github.com/Gcob/lara-spec-first/issues/254) closes it with a partial nested type.
- **A merge patch beside a type read as written is a gap with no card.** The remedy is in the contract or the key.
- **A merge patch over another schema is not read.** Describing both media types with one schema reads both. Whether a
  body in an unread media type should be judged by another type's rules at all, a JSON Patch beside a merge patch
  included, is [#247](https://github.com/Gcob/lara-spec-first/issues/247)'s, together with a `charset` parameter and a
  media type written in another case.
- **`null` is a value, not a removal,** and a JSON Patch document is not validated. Neither has a card, and each can be
  added without changing what this record decides.
