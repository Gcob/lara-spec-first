---
title: Changelog
audience: Users and contributors
covers: >
    What changed in each released version of this package, and the two conventions that decide how an entry is written:
    Keep a Changelog for the shape, and semantic versioning for what a version number is allowed to promise.
read_before: Cutting a release, or working out what a version changed before upgrading to it.
tags: [versions, conventions, planning]
---

# Changelog

> **In brief**
>
> - Every released version has an entry here, newest first, patches included and
>   [nothing ever archived](#why-this-is-one-file).
> - The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) plus one section of our own, `Known limits`,
>   and the numbering is [semantic versioning](https://semver.org/spec/v2.0.0.html).
> - A `0.x` makes no compatibility promise: what a `1.0` will freeze is
>   [named in the roadmap](./README.md#before-10-freeze-what-a-major-would-cost) rather than left to be discovered here.
> - `Unreleased` holds what is merged and not tagged. Cutting a tag renames that heading and opens a new one.

## [Unreleased]

### Changed

- **`parameters` (`header`, `cookie`) moved from `Deferred` to `Ignored`** in the
  [support matrix](./docs/guide/openapi-support.md#parameters-bodies-responses). The package will not validate a header
  or a cookie parameter, so the row states a position rather than a gap, and a contract declaring one will exit non-zero
  until the consumer acknowledges it. Every move on the exit-code axis is major in either direction, which is why a docs
  change is recorded here. The doctor still counts them beside `query` under one `Deferred` label until a parameter
  becomes a rule, so nothing about today's output changed.
- **`Media type encoding` and `contentMediaType` moved up to `Partial`**, both read for what makes a
  `multipart/form-data` part a file. Moving up the ladder cannot break a contract that worked before, so this half is
  minor.
- **`Local $ref within the document` moved down**, which the row itself announced as "a major, on the release that reads
  schemas". A reference aimed at a position inside a `$defs` is now refused. It used to be accepted and it never worked:
  the parser does not model that keyword, so the target was a plain array it could not build a schema from, and the
  property carrying the reference was dropped without a word. A contract declaring three fields came back with two.
- **Five schema keywords moved to `Rejected`**: `$id`, `$dynamicRef`, `$dynamicAnchor`, `$recursiveRef` and
  `$recursiveAnchor`. Each changes how a reference resolves, so ignoring one resolves a reference to a target the
  document never named, which is a wrong value rather than a missing one.
- **A keyword the parser hands back raw is refused when a `$ref` is inside it**: `prefixItems`, `contains`,
  `unevaluatedItems`, `patternProperties`, `propertyNames`, `dependentSchemas`, `unevaluatedProperties`,
  `contentSchema`, `if`, `then` and `else`. The refusal reads the value and not the keyword, so the same keyword holding
  inline schemas is still ignored as before, and a `$ref` written inside `example`, `examples`, `default`, `enum` or
  `const` stays the literal it is.

**What this means for a contract that used to build.** All of the above turn a build that passed into one that refuses,
which is why they are recorded together. None of them was ever served correctly: each produced a value that was wrong
rather than one that was missing, and nothing downstream failed to say so.

### Added

- **`spec:build` emits one `FormRequest` per operation that states anything about its input**, into the `Requests`
  sub-namespace of the generated tree, pruned like every other generated file. The rule set is the request body and the
  `query` parameters merged by field name; the scalar types, nullability and presence are mapped, and every keyword the
  build does not translate yet is named per field in the generated file's own findings rather than dropped. An operation
  with no body and no `query` parameter gets no class, and the build says how many.
- **The whole mapping table is built.** String and number bounds, `multipleOf`, the `format` values Laravel has a rule
  of the same meaning for (`date`, `date-time` as the RFC 3339 grammar, a fraction of any length included, `email`,
  `uuid`, `ipv4`, `ipv6`), `enum` and `const` as `Rule::in()` over an array, `pattern` as `regex:` inside the ECMA-262 /
  PCRE boundary, arrays with their element rules under `field.*`, nested objects under dotted keys, `allOf` merged,
  `dependentRequired`, an optional body's "all or none", and `additionalProperties: false` at every depth. What stays
  outside the boundary is named in the generated file's findings. Most of the support matrix's schema rows moved from
  `Deferred` to `Supported`; `format`, `pattern` and `uniqueItems` to `Partial`.
- **A file part is validated as one.** In a body that is `multipart/form-data` alone, a property the schema calls a file
  (`format: binary` at 3.0, `contentMediaType` at 3.1) becomes `file`, `mimetypes:` from `contentMediaType`, and `max:`
  in kilobytes from `maxLength`, as an exact decimal (`max:2.44140625` is 2500 bytes, which Laravel's `max` takes).
  `application/octet-stream` and `*/*` add no `mimetypes`, which would refuse every upload but an octet stream. A
  required part is `required`, so an empty upload slot is refused. Beside another media type, or in a JSON body, the
  part is a string. `encoding.<part>.contentType` is not read yet, and every file part says so in its generated file's
  findings ([#90](https://github.com/Gcob/lara-spec-first/issues/90)). A closed root also refuses an undeclared upload.
- **`spec:build` writes the input DTOs.** One `final readonly` class per request body that is an object, in the `Data`
  sub-namespace: a promoted constructor, `from()`, `toArray()`, `Arrayable` and `JsonSerializable`. Each body gets a
  full type and a `Partial` one, `NewUserInputDto` and `NewUserPartialInputDto`, named after the schema and never after
  the `$ref` or the file that holds it. A property the client may leave out is `Optional|T`, so `toArray()` never writes
  a `null` over a field that was not sent. Two different schemas under one name are a build error, and so is one schema
  with a file part sent as multipart by one operation and as another media type by another.
- **A generated request has a `dto()` method** returning the input DTO built from `validated()`: the full type for a
  `POST` and a `PUT`, the partial one for a `PATCH` and for a body that may be absent. It is `dto()` and not `data()`
  because `Illuminate\Http\Request` owns a protected `data()` that its typed accessors read through. `validated()` is
  untouched, and a request with no object body has no `dto()`.
- **A node that recurses is `mixed` on the DTO**, since no rule validates anything below it. A `date-time` is written
  back by `toArray()` with its fraction (microseconds), a component name with dots derives its class name by dropping
  them, and two operations reaching one named schema share its DTO whatever a 3.0 `$ref` wrapper carries beside it.
- **A schema in a recursive pair (`Author` and `Book`) is `mixed` where it is nested**, a required key Laravel cannot
  name is optional in the DTO, an enumeration value that could close a docblock is not written there, and a closed root
  keeps a declared integer key such as `"2024"`.
- **`Gcob\LaraSpecFirst\Data\Optional`** is public API: the one class of this package a generated DTO imports.
- **`Contract\Schema` carries `source`**, where a named schema is written, beside its `name`.
- **`generated.namespace` moved from `STARTED` to `DONE`** in the published config. It has been read since the first
  generated controller; the block said otherwise.

### Changed

- **`routeAction` takes the generated request as its first parameter**, on the generated parent and on every child that
  overrides it. **This breaks every custom controller written before it** for an operation that states anything about
  its input: the child stops compiling until it declares the parameter too. That is the split doing its job — the
  contract gained a statement about what a client may send — and `spec:make` writes the new signature. Both sides now
  read it from one place, so a scaffold cannot disagree with the parent it extends.
- **`header` and `cookie` parameters moved from a shared `Deferred` count to an `Ignored` one** in `spec:doctor`, which
  means they now exit non-zero until acknowledged. They were counted beside `query` under one `Deferred` label while
  nothing read a parameter at all; `query` is read now, so the count splits and the level with it. `query` and
  `requestBody` moved from `Deferred` to `Partial` in the support matrix, and the doctor no longer counts either.
- **A path parameter named `{request}` is refused on an operation with something to validate.** The generated request is
  declared as `$request` ahead of the path's own parameters, so the two would be one variable declared twice. A contract
  that built at `0.1.0` with such a parameter and a body or a `query` parameter now refuses to build.
- **A required key gets `present`, not `required`, unless it is an integer, a number or a boolean.** JSON Schema's
  `required` asks for the key, and Laravel's refuses `null`, `""`, `[]` and `{}`, which the contract may allow.
- **A fully-qualified name in a generated docblock's `@see` is written in backticks.** Pint's
  `fully_qualified_strict_types` rewrites an unquoted one into an import plus a short name, which means the build and a
  consumer's formatter rewriting each other forever — and for a generated controller, an import carrying the child's
  name into a parent that shares it by design, which stops the file loading.

### Fixed

- **A position naming a schema written in another file was wrong**, and it was wrong in three readings at once: a
  refusal named a pointer into the root document, which has nothing at it and does not name the offending file; a
  recursion marker cut one level too late, because the parser hands back a _copy_ of an externally resolved node and the
  walk compared object identity; and a schema's own name was unrecoverable. One cause, `getDocumentPosition()` reporting
  the first site that referenced a node, so one correction: the extractor now
  [walks the raw document itself](./docs/guide/openapi-support.md#where-a-schema-is-reported-from). **Every message
  about a schema written outside the root document changes**, from a bare `#/paths/…` pointer to
  `other.yaml#/components/schemas/…`. They were wrong before, so this is a correction rather than a break, but a
  consumer matching on those strings will see it. Nothing changes for a single-file specification.

### Added

- **`Contract\Schema` gains `name`**, what the document calls a schema: a key of `components.schemas` in whichever file
  holds it, or the file name of a schema that is a whole file. Never derived from how the `$ref` reaching it was
  spelled, so splitting a specification across files renames nothing. Null for a schema written inline.
- **`Contract\Schema` and the types around it**: `SchemaType`, `RequestBody`, `QueryParameter` and `Response`. A schema
  now reaches every generator normalized and free of any OpenAPI version, so nothing downstream branches on which one
  the document declared.
- **`Contract\Operation` gains `requestBody`, `queryParameters` and `responses`**, plus `response()` and
  `withController()`. Nothing is frozen under `0.x`, and this is the kind of move that stops being free once it is: code
  constructing an `Operation` positionally, or defining its own `withController` decorator around one, sees the surface
  change.

## [0.1.0] - 2026-09-11

The first release, and what [Phase 1](./README.md#phase-1-the-foundation) set out to prove: an OpenAPI contract becomes
routes and controllers, and nothing at runtime ever opens a specification.

### Added

- **Contract-driven routing.** The service provider registers the routes `spec:build` emitted, by loading one generated
  PHP file. It reads no specification, at boot or ever.
- **`spec:build`**, which resolves the contract and emits the routes plus one controller per operation. It plans every
  file in memory before writing any of them, so a document it refuses leaves the working tree exactly as it was.
- **`spec:make`**, the only command that creates a class you own, in three forms: one named operation, a whole `--tag`,
  or `--all`. It never overwrites a file that exists.
- **`spec:doctor`**, which reports what this package will and will not honor in your contract, plus the routing table
  that results. Read-only, with `--json` for CI and tooling.
- **The two-class seam.** Generated controllers are abstract and yours extend them, so a contract change becomes a
  static analysis error rather than a runtime surprise. An operation nobody has implemented answers `501` and names the
  command that implements it.
- **Remote reference vendoring.** `spec:build --update-refs` fetches an allowed reference once and commits the copy.
  Every other command reads the working tree and never the network.
- **OpenAPI 3.0 and 3.1**, read through one version strategy per minor and pinned by a conformance suite that writes the
  same contract in both and requires them to normalize identically.
- **Lifecycle and security reporting.** `deprecated: true` requires an `x-sunset`, and an operation declaring `security`
  is reported rather than enforced, in those words, because the route is registered with no authorization check behind
  it.

### Known limits

Not a Keep a Changelog section. It is here because a release that ships a gap on purpose owes it a line, and none of the
six standard sections says "this works, up to here".

- **`security` is reported, not enforced.** A contract declaring it exits `2` on every doctor run until enforcement
  lands. See [security.md](./docs/guide/security.md).
- **Four configuration blocks are inert**, and each names the phase that makes it live. Changing one has no effect
  today.
- **No response DTOs, no generated validation, no pagination or rate limiting.** All of it is
  [Phase 2](./README.md#phase-2-the-generated-pipeline).

## Why this is one file

**Every release lands here, patch included, and nothing is ever moved out.** Both halves of that get asked, so both are
answered here rather than re-argued each time.

**A patch gets an entry because a patch is the release nobody reads before taking.** Under `0.x` a breaking change lands
in a minor, which makes `0.1.1` the upgrade a consumer applies without thinking about it. That is exactly when it
matters that there is something to read if there is something to know. The entry usually costs one line under `Fixed`,
and a change with nothing to tell a consumer does not get tagged at all, so the empty entry never comes up.

**Laravel and Symfony split theirs, and the reason is not the version number.** They maintain several release lines at
once and backport fixes across them, so each branch carries its own history and earns its own file. This project
maintains one line: Phase 1, then `0.x`, then Phase 2, then `1.0`. Splitting that would produce several files describing
one straight line, and a link to a version's notes would break the day that version moved.

**So the criterion is more than one maintained line, not more than one minor.** The day a fix is backported into `0.1.x`
while `0.2.0` is already out, per-branch files start paying for themselves. Until then they cost a reader the one thing
this file is for, which is not having to work out where to look.

**And the split would be by major.** `CHANGELOG.md` would keep the current major and earlier ones would move under
`docs/`. A major is already the boundary where a consumer changes worlds; a minor is a boundary for nobody. Size on its
own is not a reason to do it: ten releases a year at twenty lines each is two hundred lines a year, and anyone who wants
one version on its own already has its [GitHub release page](https://github.com/Gcob/lara-spec-first/releases).

[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0
