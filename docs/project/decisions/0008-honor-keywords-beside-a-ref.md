---
title: "ADR 0008: Keywords beside a `$ref` are honored in OpenAPI 3.1 and ignored in 3.0, responses included"
audience: Users and contributors
covers: >
    The decision to read the keywords written beside a `$ref` together with the schema it names at 3.1 and to ignore
    them at 3.0, how they are read from the raw document, which of them are reported, why a response schema is read the
    same way, and the alternatives turned down.
read_before: >
    Asking why `{$ref: Code, maxLength: 3}` gets a rule at 3.1 and none at 3.0, changing which keywords beside a `$ref`
    are read, or adding a reader of response schemas.
tags: [decisions, code-generation, openapi, versions]
---

# ADR 0008: Keywords beside a `$ref` are honored in OpenAPI 3.1 and ignored in 3.0, responses included

> **In brief**
>
> - At 3.1, every keyword written beside a `$ref` applies together with the schema the `$ref` names, as JSON Schema
>   2020-12 reads it: `{$ref: Code, maxLength: 3}` is `Code` and a ceiling of three.
> - At 3.0, nothing beside a `$ref` is read, as its specification says, and the doctor names nothing there.
> - The keywords are read from the raw document, because the parser drops them. Those that add something a generator
>   reads make one `allOf` wrapper, and the rest leave the referenced schema as it is.
> - A response schema is read the same way as a request schema, since one schema has one reading wherever it is reached.
> - Turned down: dropping the keywords at 3.1, refusing a document that writes them, reading only some of them, and
>   reading them for requests only.

**Status:** Accepted · **Cards:** [#118](https://github.com/Gcob/lara-spec-first/issues/118) and
[#109](https://github.com/Gcob/lara-spec-first/issues/109) (a schema `additionalProperties` beside a `$ref`) · **Pull
requests:** [#136](https://github.com/Gcob/lara-spec-first/pull/136) and
[#170](https://github.com/Gcob/lara-spec-first/pull/170)

## Context

A schema that writes a `$ref` can write other keywords beside it: `{$ref: Code, maxLength: 3}`,
`{$ref: Base, deprecated: true}`, `{$ref: Base, required: [id]}`. The two versions disagree about them. OpenAPI 3.0 says
everything beside a `$ref` is ignored. OpenAPI 3.1 made a Schema Object JSON Schema 2020-12, which applies those
keywords together with the referenced schema, so the first example is the same contract as
`allOf: [{$ref: Code}, {maxLength: 3}]`.

The parser keeps `$ref`, `summary` and `description` of a node that writes a `$ref` and drops the rest, with no error
and no trace (`drops every keyword written beside a $ref`, in `KnownParserBugsTest.php`). So a 3.1 constraint written
there was thrown away before this package read anything, the generated rule was missing, and a request the contract
refuses answered `200`. That is the direction [ADR 0006](./0006-hold-rules-to-the-contract.md) rules out: nothing is
dropped in silence.

## Decision

**At 3.1, every keyword beside a `$ref` applies together with the referenced schema.** The version's strategy hands the
keys back, every one but `$ref` (`OpenApi31Strategy::referenceSiblings()`), and the extractor builds them as a schema of
their own through the same reading every schema goes through. A request that carried them is checked by them:
`{$ref: Code, maxLength: 3}` gets `max:3`, and `ref-siblings-request.yaml` is a `422` for a code of four characters and
a `200` for three.

**At 3.0, nothing beside a `$ref` is read** (`OpenApi30Strategy::referenceSiblings()` returns nothing). The 3.0
specification says so, and reading them would make the package accept a reading the version does not have. `spec:doctor`
names nothing for them at 3.0.

**The keywords are read from the raw document, where the `$ref` is written.** An alias of an alias writes keywords at
every hop, and each hop is read where it is written, from the innermost out, in the file that wrote it.

**What comes back depends on what the keywords add:**

- **Something a generator reads** (a constraint, `required`, `properties`, `items`, `allOf`, `readOnly`): one `allOf`
  wrapper, the referenced schema first and the keywords' branch after it, which is how 3.0 spells the same contract. The
  wrapper keeps the referenced schema's class name unless it adds shape a DTO holds. `Alias: {$ref: Node, maxLength: 3}`
  is still `Node` to a generated class, and `Alias: {$ref: Node, required: [id]}` is a schema of its own.
- **Nothing** (`description`, `title`, and the keywords the support matrix leaves `Ignored`, such as `deprecated` and
  `default`): the referenced schema itself, with the position of each `Ignored` keyword carried on a copy, so
  `spec:doctor` reports it where it is written.

**The same rules apply to these keywords as anywhere.** `properties`, `items` and `allOf` are read, and a `$ref` inside
them is followed. `oneOf`, `anyOf` and `not` are reported `Ignored`, even when one holds a `$ref`. A raw keyword holding
a `$ref` (`prefixItems`, `if`) and `$id` or a dynamic reference are refused, as they are everywhere. A schema
`additionalProperties` is read like any other, its `$ref` followed, and the doctor reports it `Partial` where no rule
carries it ([ADR 0003](./0003-validate-each-value-of-a-map.md)). A keyword that contradicts the referenced schema
(`{$ref: User, additionalProperties: false}` over a target that declares properties, `{$ref: Code, type: integer}`) is
refused by the build, the way two `allOf` branches that disagree are.

**An `x-max-depth` beside a `$ref` is reported `Ignored` and never honored.** A recursion marker reads the depth of the
schema it points back at, so a depth written on the way there would be read by nothing. The message says to write it on
the recursive schema itself.

**A response schema is read the same way.** One schema has one reading wherever it is reached, so
`{$ref: Code, maxLength: 3}` in a response is `Code` and a ceiling of three, as it is in a request body or a query
parameter (`reads siblings beside a $ref in a response schema`).

## Alternatives turned down

- **Keep dropping them at 3.1.** A constraint the 3.1 contract states would be enforced by nothing and reported by
  nothing, which is the silence this decision removes.
- **Refuse a 3.1 schema that writes them.** It stops the build of common 3.1 documents, such as
  `{$ref: X, deprecated: true}`, for a keyword the contract allows.
- **Read the constraints, and refuse the keywords that hold schemas until later.** The same documents stop building, for
  `properties` or `items` written beside a `$ref`.
- **Read them for requests only, and for responses later.** One schema would have two readings depending on where it is
  reached, and a reader of response schemas added later would have to be taught which one applies.

## Consequences

- **A 3.1 request is stricter than it was** for a document that writes these keywords: a value its author's words
  refused and the generated request let through is now a `422`.
- **A contradicting keyword stops the build** instead of being dropped, as two `allOf` branches that disagree do.
- **Three cases are refused with a message that names where they are written**, where they would otherwise be a silent
  gap: a `$ref` inside those keywords whose target is not there, or whose chain comes back to one it already followed
  through other files; a keyword the parser cannot build a schema from (`{$ref: Code, items: "x"}`); and a `$ref` aimed
  into a `$defs`, which is refused as it is anywhere. The first two are a fault in the document for the operation that
  writes the keyword, and the rest of the document still reads.
- **A 3.0 author who writes a constraint beside a `$ref` gets no rule and no finding.** That is how the 3.0
  specification reads it, so the package says nothing, and an author who wants the constraint writes the `allOf`.
- **An `x-max-depth` belongs on the recursive schema.** One written beside a `$ref` is reported and does nothing
  ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)).
- **A response reader added later needs no rule of its own for these keywords:** the schema it receives is already read.
