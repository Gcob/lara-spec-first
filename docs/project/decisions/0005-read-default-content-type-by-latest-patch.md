---
title: "ADR 0005: A part's default `contentType` follows the latest patch text of its OpenAPI minor"
audience: Users and contributors
covers: >
    The decision to read each OpenAPI minor's default `contentType` of a multipart part from its latest patch text, to
    hand that table to the request body from the version's strategy so that nothing downstream learns the version, and
    the alternatives turned down.
read_before: >
    Writing a `contentType` under `encoding` that the doctor reports, asking why a 3.1.0 document is read with 3.1.1's
    defaults, or changing what a version's strategy hands the request body.
tags: [decisions, openapi, compatibility]
---

# ADR 0005: A part's default `contentType` follows the latest patch text of its OpenAPI minor

> **In brief**
>
> - Each OpenAPI minor is read by its latest patch text for the `contentType` a part gets when `encoding` writes none:
>   3.0.x by 3.0.4, 3.1.x by 3.1.1. A 3.1.0 document gets 3.1.1's defaults.
> - The table is handed to the request body by the version's strategy. The rule set reads it and learns no version, and
>   a body built by hand keeps the widest table.
> - Turned down: the union of a minor's patches, a version on the contract, normalizing a written default away when the
>   document is parsed, and a field on every `Schema`.

**Status:** Accepted · **Card:** [#131](https://github.com/Gcob/lara-spec-first/issues/131)

## Context

`encoding.<part>.contentType` narrows a file part of a `multipart/form-data` body. On a part the request does not read
as a file, the package cannot enforce it, so it reports it, unless every type written is the part's default: a contract
that spells out the default has said nothing new. What the default is depends on the version, and the patches of one
minor disagree:

- 3.0.3 gives `application/octet-stream` to a `string` with `format: binary` and `text/plain` to any other primitive.
  3.0.4 adds `format: byte` to the `application/octet-stream` row.
- 3.1.0 gives `application/octet-stream` to everything but an object and an array, primitives included. 3.1.1 gives
  `text/plain` to a number, an integer, a boolean and a string with no `contentEncoding`, and `application/octet-stream`
  to a part with no `type` and to a string with a `contentEncoding`. 3.1.2 repeats 3.1.1's table.

The specification says that "The patch version SHOULD NOT be considered by tooling", and that patch versions "address
errors in, or provide clarifications to" the document. The package keeps only the minor (`SpecVersion`), so it cannot
tell a 3.1.0 document from a 3.1.1 one.

Until now the rule set took the union of every version's defaults, since it knows no version. A type written on a part
that no version meant as a default was reported, and one that some version meant was not, whichever version the document
declared. A 3.0 binary part written `text/plain` was silent in a form-encoded or mixed body, though 3.0 says its default
is `application/octet-stream`.

The version seam says that nothing downstream of a strategy knows which version was loaded, and the rule set sits
downstream.

## Decision

**A minor is read by its latest patch text.** 3.0.x is read as 3.0.4, 3.1.x as 3.1.1 (3.1.2 repeats it), so a 3.1.0
document is read with 3.1.1's defaults. A patch corrects the text of its minor, so the latest text is the contract, and
the patch a document declares is not read.

**The version's strategy hands a table, never the version.** `VersionStrategy::encodingDefaults()` returns an
`EncodingDefaults` stating the four kinds of part whose default differs, in normal-form terms: a `type: string` file
part, a string, a `format: byte` string, and a number, an integer or a boolean. The extractor puts it on the
`RequestBody`, and the rule set reads it from there. What no version disagrees on stays in the rule set: an object is
`application/json`, an array has its items' default, and a file part with no `type` or a part with no `type` at all is
`application/octet-stream`.

| Part                               | 3.0.x (3.0.4)              | 3.1.x (3.1.1) |
| ---------------------------------- | -------------------------- | ------------- |
| A file part written `type: string` | `application/octet-stream` | `text/plain`  |
| A `format: byte` string, no file   | `application/octet-stream` | `text/plain`  |
| Any other string, no file          | `text/plain`               | `text/plain`  |
| A number, an integer or a boolean  | `text/plain`               | `text/plain`  |

**A body built by hand keeps the widest table**: each of the four kinds is both `text/plain` and
`application/octet-stream`. A body that names no version reports nothing a version could call a default. The one
exception is a file part with no `type`, which is `application/octet-stream` in every text, so `text/plain` on it is
reported.

**A multipart body alone is unchanged.** There a file part's `contentType` is read as the file types it accepts, not
compared with a default. A `type: string` file part written `text/plain` is therefore silent in a form or mixed body at
3.1, and reported in a multipart body alone, where the request reads the part as a file and no `mimetypes` rule matches
a text type. `application/octet-stream` is the reverse: reported in a form or mixed body, and silent in a multipart body
alone, where it means "any bytes".

## Alternatives turned down

- **The union of a minor's patches.** It is looser than the contract: a 3.1.1 document that writes
  `application/octet-stream` on an integer writes a type the request does not enforce, and the union would stay silent
  because 3.1.0 gave it. A patch corrects its minor, so the latest text is the one to hold a contract to.
- **A version on the contract.** The rule set would branch on it, which is the check the version seam exists to keep out
  of everything downstream of a strategy.
- **Normalizing a written default away when the document is parsed.** It would change which file types a multipart-only
  body accepts: `application/octet-stream` written on a file part would stop meaning "any bytes" and fall back to
  `contentMediaType`, a stricter rule that refuses uploads the contract allows.
- **A field on every `Schema`.** An `allOf` merge cannot combine per-member answers, and the default belongs to the
  part, not to the schema it is written on.

## Consequences

- **A 3.1.0 contract that spelled out 3.1.0's default for a primitive is now reported.** `application/octet-stream` on a
  string, a number, an integer or a boolean was silent, and is reported at 3.1.x. `spec:doctor` moves from exit `0` to
  exit `2` for such a contract, which is a breaking change.
- **A 3.1 string with a `contentEncoding` reads as a plain string.** 3.1.1 gives it `application/octet-stream`, but
  `Schema` does not carry `contentEncoding`, so `application/octet-stream` written on it is reported and `text/plain` is
  not, until the schema carries the keyword.
- **A 3.1 `contentType` written beside `style`, `explode` or `allowReserved` is still read.** From 3.1.0 the
  specification ignores it there, and the package reads only `contentType`. This decision does not change that.
- **In a multipart body alone, a file part's `contentType` is still read as the file types it accepts,** so the two
  differences above (`text/plain` and `application/octet-stream` on a 3.1 `type: string` file part) stay.
- **A later patch that changes the table changes one strategy,** and nothing else.
