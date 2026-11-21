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
> - The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) plus one section of our own, `Known limits`
>   (the gaps a release ships on purpose, each with the card that closes it), and the numbering is
>   [semantic versioning](https://semver.org/spec/v2.0.0.html).
> - A `0.x` makes no compatibility promise: what a `1.0` will freeze is
>   [named in the roadmap](./README.md#before-10-freeze-what-a-major-would-cost) rather than left to be discovered here.
> - `Unreleased` holds what is merged and not tagged. Cutting a tag renames that heading and opens a new one.
> - An entry is written against the previous release: "before" means that release, not `main` a week ago, so an entry
>   that fixes or contradicts something no release shipped is dropped or merged.

## [Unreleased]

### Added

- **`query.deep_object_arrays` says how a client writes an array inside a `deepObject` query parameter.** OpenAPI leaves
  it undefined, so the app declares it in `config/lara-spec-first.php`. `brackets`, the default
  (`?filter[ids][]=1&filter[ids][]=2`, or `filter[ids][0]=1` as PHP's `http_build_query()` writes it), checks the array
  as a list, with its items, `minItems`, `maxItems` and `uniqueItems`, and refuses a plain string. `comma`
  (`?filter[ids]=1,2`) has the generated request split the string in `prepareForValidation()` before it validates, then
  checks the same, judges an empty item (`?filter[ids]=1,,2`) as `null`, as a bracketed empty item is, and still accepts
  a list written with brackets. Under `brackets`, a required array whose schema accepts `[]` may be left out, and so may
  one a `dependentRequired` makes required, since an empty list has no bracketed form. Under `brackets` and `comma`, the
  generated request says in one line of information per array how it is read and which key changes it; nothing is
  counted, so the doctor's exit code does not move. `any` checks only that the array is there, with a finding, as before
  the key existed. `spec:build` reads it, so a change takes another build, and `spec:doctor` reads it too, so it reports
  the generated requests as drifted until that build. Any other value stops `spec:build`, `spec:make` and `spec:doctor`
  with a message that names the key. The reasoning is in
  [ADR 0023](./docs/project/decisions/0023-read-an-array-in-a-deep-object-as-the-app-declares.md). This ships
  [#218](https://github.com/Gcob/lara-spec-first/issues/218).
- **`spec:build` names a custom controller whose `routeAction` no longer fits the parent it regenerated.** When the
  contract gains or loses a request or a path parameter, a child that still declares the old list is a class PHP refuses
  to load at its first request. The build now prints one header and one line per such child, with the signature to paste
  (the request is written fully qualified, so the line loads in a child with no `use` for it), and still exits `0`: it
  warns rather than refuses, since a refused build would never generate the request class the child has to match. It
  reads the file and never loads the class, and it judges what is sound without resolving one: the parameter count, and
  a return type that says `mixed` or nothing under a parent that declares a response DTO (the signature to paste carries
  the parent's return type, written in full). A renamed request class, a narrowed type or a class that is not the
  parent's is still reported by PHP when the class loads, and by PHPStan before. A child that extends something other
  than its generated parent, or that gets `routeAction` from a trait, is not checked. This closes the second half of the
  `0.2.0` `Known limits` item for [#112](https://github.com/Gcob/lara-spec-first/issues/112).
- **`spec:build` writes a response DTO for each documented response shape, and the generated controller names it.** A
  declared status (`200`, `201`, `2XX`, `default`) with a JSON body that is an object, or a list of objects, gets a
  `final readonly` class in the `Data` sub-namespace, named after its schema without `Input` (`$ref: User` gives
  `UserDto`) or, for a schema written inline, after its operation and status (`CreateUser201ResponseDto`). An optional
  property is `Optional|T`, a `writeOnly` one has no property, nothing is cast, a `date-time` is written as Laravel's
  `toJSON()` writes it, and `jsonSerialize()` hands nested DTOs and every map to the encoder as JSON objects, so an
  empty map is `{}`. A response DTO is `JsonSerializable` and not `Arrayable`, so `response($dto, 201)`, a paginator and
  `Collection::toArray()` write that `{}` too, where Laravel would call `toArray()` on an `Arrayable` and write `[]`. An
  object with no `properties` is carried whole under `additionalProperties`. A response property the contract removes is
  a changed constructor, so the code that reads it fails static analysis. The generated controller's findings now name
  the DTO of each status, or say why it has none (no body, no JSON media type, two JSON media types with different
  schemas, a body that is not an object or a list of objects), where they said "The return type is `mixed` because no
  response schema is read yet". A response schema with a fault of its own gets no DTO instead of stopping the build: a
  schema name that leaves no class name, two keys that derive one property name, and `allOf` branches that disagree. The
  status that would have used it says why in the generated controller, and a property that would have held it is `mixed`
  with a finding, while everything else is typed as the contract writes it. So does a response schema whose class name
  another schema claims, such as an inline `address` beside a component `UserAddress`, two components `new-user` and
  `NewUser`, or a response schema `UserInput` beside a request schema `User`: an input DTO outranks a response schema, a
  component outranks a schema written inline, and two schemas of one rank that derive one class name both give way, so
  reading the document in the other order writes the same classes and types (a class shared by several operations lists
  them in the document's order in its `Read by` line). `spec:build` and `spec:make` go on for every one of these as they
  did on `0.2.0`, and `spec:doctor` stays clean on them: a schema at fault is not a keyword the doctor names, the build
  says it in the generated file. No factory is generated yet. The files and the sentence are not breaking on their own,
  but what the generated controller now returns is: see the `Changed` entry on `routeAction`'s return type. The
  reasoning is in [ADR 0013](./docs/project/decisions/0013-mirror-a-response-schema-or-leave-it-untyped.md). This ships
  [#39](https://github.com/Gcob/lara-spec-first/issues/39) and
  [#178](https://github.com/Gcob/lara-spec-first/issues/178).

### Changed

- **A generated `routeAction` now returns the DTO of the operation's success statuses, and the generated parent sends
  the status the contract declares.** `GET /users/{id}` with a `200` that is a `User` generates
  `routeAction(string $id): UserDto`, where it was `mixed`. A list is `array` (a `@return list<UserDto>` says of what),
  a body that may be `null` is `?UserDto`, and several success statuses with a DTO make a union. Several success
  statuses add `JsonResponse`, and a contract that declares an answer that is not a JSON body (a `204`, a `text/csv`
  body, a redirect) adds Symfony's `Response`. An operation with no DTO on a success status stays `mixed`. An error
  status is never part of the type: throw it (`abort()` or an exception), and the DTO of an error response is for the
  exception handler. The generated parent also defines `callAction()`, which Laravel calls with what `routeAction`
  returned, and sends the status the contract declares through the new `Gcob\LaraSpecFirst\Http\SuccessStatus`: a DTO,
  an array, a scalar or `null` answers `201` where the contract says `201`, with no `response()->json($dto, 201)`, and a
  `Response`, a `Responsable`, a PSR-7 response or a `Model` passes to Laravel untouched. A child needs no `parent::`
  call for it. `spec:make` writes the same return type. This is a breaking change: **a custom controller that declares
  `routeAction(...): mixed` under a typed parent is a class PHP refuses to load, at its first request**, and so is one
  that returns a type the parent no longer allows. Change each to the type its parent declares. `spec:build` names every
  such child, and prints the signature to paste (see the `Added` entry on the custom controllers it names): one that
  declares `mixed` or no return type, including no return type under a parent that returns `mixed`, and one that
  declares a built-in type its parent does not hold, such as the `: array` an upgrade from `0.2.0` leaves under a DTO,
  `: TeamDto|array` under `TeamDto`, or `: ?UserDto` under `UserDto`. A class the child names is still left to PHP. A
  controller that returned a plain DTO for a `201`, or `null` for a `204`, now answers the status the contract declares,
  where it answered `200`. A controller of an operation whose lone success has a JSON body and no DTO, and that returns
  `null`, now answers a JSON `null`, where it answered an empty `200`. The reasoning is in
  [ADR 0017](./docs/project/decisions/0017-return-the-response-dto-from-routeaction.md), and this ships
  [#165](https://github.com/Gcob/lara-spec-first/issues/165).
- **An optional JSON body that requires properties now refuses `{}` sent as JSON, and a JSON object that carries none of
  them, where it used to pass.** A body with `requestBody.required: false` and a schema that requires `reason` meant "a
  body, or none", but a rule cannot tell `{}` from no body, so `{}` and `{"zzz": 1}` were accepted. Sent with a type
  Laravel reads as JSON (`application/json`, or a `+json` type such as `application/vnd.api+json`), they answer `422` to
  a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)),
  on each required key the object lacks. An absent body (no content, or only blanks) and a JSON `null` on a nullable
  body still pass, and so does a `{}` sent as `text/plain` or with no `Content-Type`: Laravel does not decode it, so the
  request reads it as an absent body. A form that sends none of the body's keys is still accepted, and the generated
  file says so in a finding. The finding no longer names `{}`, and a JSON-only body no longer carries it. This is a
  breaking change: a client that sent `{}` as JSON and got a `200` now gets a refusal. Send the key, or send no body.
- **A required body sent with an empty content and a type that is not a form is refused, where it used to pass.** An
  `application/xml` or `text/plain` request (compared without case, parameters like `; charset=utf-8` ignored) with no
  content, or only blanks, answers `422` to a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)),
  on the body's own key (`body`, or `_body` when a property is named `body`). It was accepted when no property refused
  the empty body, and when a required property did, it was already refused on the property's own key and the body's key
  now joins it. A form type (`application/x-www-form-urlencoded`, `multipart/form-data`) is still not read as absent,
  spelled in capitals or not, and a request with no `Content-Type` or with JSON was already read as absent. This is a
  breaking change: a client that sent such a request and got a `200` now gets a refusal. Send the body, or make it
  optional in the contract.
- **The input DTO property of a required name a form nests another declared name under is now `Optional`, and an array
  never reaches it.** In a body read only as a form, a required `filter[a]` beside `filter[a][b]` (and a required `page`
  beside `page[size]`, which was already `Optional`) is judged only when its value is not an array, and the array of the
  nested names stands in for it, so the payload cannot promise it: the property type gains `Optional` (`string` becomes
  `Optional|string`). `dto()` hands `from()` the name only when it is not an array, so `filter[a]=x` reaches it and
  `filter[a][b]=1` reaches `filter[a][b]` and never a `filter[a]` of `1`. This is a breaking change for code that read
  that property: it holds `Optional` where it held the value. Check for it, or read the value from the request
  (`$request->input('filter.a')`). The reasoning is in
  [ADR 0012](./docs/project/decisions/0012-judge-plain-form-names-by-shape.md), and this ships
  [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **A body read only as a form that declares `page` beside `page[size]` now judges both, where neither had a rule.** A
  form delivers `page[size]=3` as `page => [size => 3]`, so the two names share a key. `page` now keeps its rules behind
  a shape check (judged only when its value is not an array), as a `query` name does, and `page[size]` keeps its own,
  under `page.size`. It holds at any depth (`filter[a]` beside `filter[a][b]`), for a list (`ids` beside `ids[]`, which
  a form reads as `ids.0`) and for a name below two others that arrive under one key. Requests that answered `200` now
  answer `422` to a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)):
  `page=abc` and `page[size]=abc`, a required `page` with nothing sent, and `page=2` alone where an optional body asks
  for more once a key is sent (its "all or none") or a `dependentRequired` asks for it. `page` and `page[size]` also
  trigger a `dependentRequired` now: `page=2` without the `other` it makes required answers `422`, and so does
  `page[size]=3` alone, which is stricter than the contract and stated in the generated file. `dto()` now carries
  `page[size]`, and a required one's property drops `Optional` (`Optional|int` becomes `int`). `spec:build` no longer
  warns about these names. One blind spot, stated: a `page` whose presence the contract asks for (required, a
  `dependentRequired` dependent, or a required name of an optional body) is satisfied by `page[size]=3` alone, as on the
  query side. This is a breaking change: send values the contract describes, or loosen the contract. The reasoning is in
  [ADR 0012](./docs/project/decisions/0012-judge-plain-form-names-by-shape.md), and this ships
  [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **A closed form body that declares two names under one bracketed key now refuses an undeclared key beside them, where
  it used to accept it.** PHP delivers `a[c]` and `a[c]x` both as `a.c` (it drops what follows a closing bracket), and
  the level above is now closed (`a` takes only `c`), where the key check accepted anything under `a`. A request that
  sent `a[evil]=1` to such a body and got a `200` now gets a `422` to a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).
  The shared key itself is still not checked, as the generated file says: `a[c]=abc` and `a[c][evil]=1` pass, and what
  was sent there is left out of `validated()`. A body that declares nothing else under `a` now gets `a` empty there
  (`['a' => []]`) where it got nothing, and one that declares another name under `a` gets only that name. When a name is
  declared below the shared key (`a[c][d]`), the key takes only that name, so `a[c][evil]=1` is still refused (unless a
  `*`, comma or appended key sits below it, which the file names: it answers `200` then), and a value that is not an
  array (`a[c]=5`), which used to be refused, passes and is left out. A body that also declares `a` itself is not closed
  yet: it still accepts `a[evil]=1` ([#206](https://github.com/Gcob/lara-spec-first/issues/206)). This is a breaking
  change: drop the key, or declare it.
- **A closed form body that declares `page` beside `page[size]` now refuses an undeclared key below `page`, where it
  used to accept it.** The body is closed to its declared names, but the level `page` is delivered at was left open for
  the value of `page` itself, so `page[evil]=1` and `page[size]=3&page[evil]=1` were accepted (the second kept only
  `size` in `validated()`). The array branch of `page`'s shape check now holds the keys the contract declares below it
  (`['array:size']`), and `page=2` and `page[size]=3` still pass. It holds at any depth: `filter[a]` beside
  `filter[a][b]` gives `filter[a][evil]=1` the same refusal, and so does a `filter[a]` whose nested names are all
  withheld (`filter[a][b]` typed `object` with `filter[a][b][c]` below it), where the level stayed open because its
  array branch drops the array (`exclude`): it now holds the keys first (`['array:b', 'exclude']`). Requests that
  answered `200` now answer `422` to a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).
  A level whose key `array:` cannot name stays open, and the generated file says so: a comma in a key, and a `*` name
  (`page[*]` beside `page[size]`), whose key Laravel hides behind a placeholder, so `page[*]=5` passes and so does
  `page[evil]=1`, as before. A `page` beside only `page[*]` keeps its plain rule in a closed body (shaping it would
  leave its level open), so `page[evil]=1` stays refused, and so does the contract's `page[*]=5`, which the file says.
  This is a breaking change: drop the key, or declare it. The reasoning is in
  [ADR 0012](./docs/project/decisions/0012-judge-plain-form-names-by-shape.md), and this ships
  [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **A body that is JSON and a form and declares `page` beside `page[size]` now judges `page`, where neither name had a
  rule.** `page` keeps its rules behind a shape check (judged only when its value is not an array) for both clients, and
  `page[size]` keeps its JSON rule under the literal key `page[size]`, as before: a form client's `page[size]` is still
  not checked. Requests that answered `200` now answer `422` to a client that asks for JSON (see
  [What a client gets when a request is refused](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)):
  `page=abc` from a form client and `{"page": "abc"}` from a JSON client; a required `page` left out, from either
  client; for a body that may be absent, a JSON `{}` (refused on `page` through the required-keys check); and a
  `dependentRequired` that `page` triggers now asks for its dependents (`page=2` without the dependent answers `422`,
  and so does a form's `page[size]=3` alone, which is stricter than the contract and stated in the generated file).
  `dto()` now carries `page`. Two limits, stated in the generated file: a `page` whose presence the contract asks for is
  satisfied by a form's `page[size]=3` alone or by a JSON array under it, and **a JSON client's array under `page`
  (`{"page": [1]}`) passes and is left out of `validated()`,** where the contract refuses it, since `rules()` does not
  branch on the media type. The build counts it as a constraint it does not enforce. This is a breaking change: send
  values the contract describes, or loosen the contract. The reasoning is in
  [ADR 0012](./docs/project/decisions/0012-judge-plain-form-names-by-shape.md), and this ships
  [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **`ext-tokenizer` is now a declared requirement.** `spec:build` reads a custom controller with PHP's tokenizer to
  compare its `routeAction` with the generated parent. This is not a breaking change: every Laravel application already
  has the extension, since the framework requires it, and `composer` only now says so in this package's own
  `composer.json`.
- **On an operation whose JSON body allows `null`, `dto()` is now typed `?X` and returns `null` for a `null` body, where
  it returned a DTO of `Optional`s.** That is an optional body, a `PATCH` and a required body that requires nothing,
  each sent a JSON `null`. A required body that requires a property is typed `?X` too: it now lets `null` through (see
  `Fixed`), so there static analysis asks for the null check as well. An absent optional body (no content) still gives
  the DTO. A body that cannot be `null`, and a nullable one read only as a form, keep the plain type. This is a breaking
  change: `$request->dto()->toArray()` on such an operation now fails at runtime when a client sends `null`, and static
  analysis asks for a null check. Handle `null`, for instance `$request->dto()?->toArray()`.
- **A `query` name PHP rewrites is now validated under the key PHP delivers, where it was reported and never checked.**
  `page[size]` is validated as `page.size`, `filter[created][gte]` as `filter.created.gte`, and the names PHP only
  renames follow (`user.name` arrives as `user_name`, `a b` as `a_b`). The error key is `page.size`, `validated()` holds
  the value nested and as the string a query string sends (`['page' => ['size' => '3']]`), and `QUERY_KEYS` in the
  generated request holds the key each parameter arrives under (`page`). Three requests that answered `200` now answer
  `422` to a client that asks for JSON, each of them already refused by the contract: a value of the wrong type
  (`?page[size]=abc` for an integer), a value outside a bound (`?page[size]=0` against `minimum: 1`), and a request that
  leaves out a required `page[size]`. A name PHP appends to (`ids[]`, `a[][b]`, `a[]b`) or delivers under no name
  (`[x]`), and a rewritten name that arrives under a key the body uses or under the same key as another `query` name,
  keep their finding and get no rule. This is a breaking change: a client that sent one of the three requests above and
  got a `200` now gets a refusal. Send the value the contract describes, or loosen the contract. This closes the `0.2.0`
  `Known limits` item for [#110](https://github.com/Gcob/lara-spec-first/issues/110).
- **`spec:doctor` no longer reports the keywords of a `query` name PHP rewrites, so its exit code can drop from `2` to
  `0`.** It reported `required` and each bound of `page[size]` or `user.name` as unenforced, since they had no rule.
  They have one now (see the entry above), so a document whose only `Partial` findings were those names exits `0`. A
  name PHP appends to, delivers under no name, or that arrives under the same key as another one keeps its keywords in
  the report, and the doctor still exits `2` for it. This is a breaking change: every move on the exit-code axis is
  major, in either direction, so a CI step that expected `2` here will see `0`. Update what it expects.
- **`spec:doctor` now reports the keywords of a plain `query` name that arrives under the same key as a name PHP
  rewrites, so its exit code can rise from `0` to `2`.** `user_name` beside `user.name` (and `a_b` beside `a b`) get no
  rule, since the value of one is the value of the other (see `Fixed`), so the `required` and each bound the plain name
  states are unenforced, where the doctor reported nothing for it while its rule judged both. This is a breaking change:
  every move on the exit-code axis is major, in either direction, so a green CI step that expected `0` here can turn
  red. Rename one of the two, or accept what the report says. A plain name beside the names PHP nests under it (`page`
  beside `page[size]`) keeps its rules, so the doctor reports nothing for it.

- **A schema written under `additionalProperties` is now read into the contract.**
  `additionalProperties: {type: integer}` on a body or a property, and the same beside a `$ref` at 3.1
  (`{$ref: Base, additionalProperties: {$ref: Pet}}`, with the `$ref` followed), is carried as the schema every value
  must satisfy, and a keyword written inside it is reported at its own position. Beside a `$ref` it was reported
  `Ignored` and no longer is. What the generated request and `spec:doctor` do with it is in the two entries below. This
  is not a breaking change on its own: it only reads what was ignored.
- **An `allOf` whose branches write an `additionalProperties` schema is now merged, and refused when the schema
  contradicts a property another branch declares.** Two branches writing one are combined, and one branch's schema
  applies to the properties the other declares, as JSON Schema reads it, so
  `allOf: [{properties: {name: {type: string}}}, {additionalProperties: {type: integer}}]` fails the build with the
  operation named, and `spec:doctor` reports it as a package limit, where the build accepted it because the schema was
  ignored. A branch that writes `additionalProperties: false` still closes the merged object. This is a breaking change:
  a document that builds today and writes such branches stops building. Make the branches agree.
- **A wrapper that writes an `additionalProperties` schema now has an input DTO class of its own.** A 3.0 body written
  `allOf: [{$ref: Pet}], additionalProperties: {type: string}` was `PetInputDto` and is now `CreatePetInputDto`, with
  the same properties, and a 3.1 `Alias: {$ref: Pet, additionalProperties: {type: string}}` was `PetInputDto` and is now
  `AliasInputDto`. The wrapper is a schema of its own, as one that writes `required` is, and a class named after `Pet`
  could not tell the two apart. This is a breaking change: code that imports or type-hints the old class name no longer
  finds it. Use the new name.
- **The values of a map are now validated, where they reached `validated()` unchecked.** An object that declares no
  `properties` and writes a schema under `additionalProperties`
  (`{type: object, additionalProperties: {type: string, maxLength: 30}}`) has each value judged by that schema's own
  rules, under `*` at the root and `field.*` nested, as an element of a list is. A key the object requires without
  declaring it is judged too, and a recursion through a map is cut on its values at `x-max-depth`, where an empty map
  passes. Two `allOf` branches that each write a value schema are merged, and `additionalProperties: {}` is read as
  `true`. An object that declares `properties` beside a value schema has the values of its undeclared keys judged too,
  and a declared key keeps exactly its own rules: the wildcard is written as a rule of the package, `UndeclaredKeys`
  (`Gcob\LaraSpecFirst\Validation\UndeclaredKeys`, a new class name a generated request imports), that spares the
  declared names whatever the order of the array. `validated()` now holds those undeclared keys beside the declared
  ones, and the DTO of such an object still carries the declared properties only, which its file says. What stays
  reported and not validated, with a finding and a `Partial` from `spec:doctor`: a declared name Laravel cannot tell
  from an undeclared key (one holding `*`, a `.` Laravel replaces, a form name nested in another such as `page[size]`, a
  name a body that is JSON and a form reads two ways), an undeclared form key that arrives under a declared name's key
  (`user_name` beside `user.name`). A root map beside a `query` parameter is validated too: the guard spares the key the
  parameter arrives under, which keeps its own rules. This is a breaking change: a request that answered `200` with a
  value the schema refuses (`{"draft": 5}` for a string of at most 30 characters, `{"views": "x"}` beside a declared
  `locale`, or beside a `query` parameter) now answers `422` to a client that asks for JSON. Send values the contract
  describes, or loosen the schema. A root map keyed by IDs (`{"1042": "abc"}` for a map of integers) is judged as any
  other, on the key itself, and so is a root that declares properties beside a value schema, except under a declared
  name. A map beside declared keys costs about 7 seconds of validation per megabyte of map near 1 MB (0.3 seconds for
  10,000 keys), so cap the request body at the web server. The reasoning is in
  [ADR 0014](./docs/project/decisions/0014-judge-undeclared-keys-by-name.md). This ships
  [#169](https://github.com/Gcob/lara-spec-first/issues/169).
- **`spec:doctor` no longer reports a map the request validates, so its exit code can drop from `2` to `0`.** A body or
  a property written `additionalProperties: <schema>` was reported `Ignored`, and a document whose only gap was one
  exited `2`. The values are validated now (see the entry above), and `additionalProperties: {}` states nothing, so such
  a document exits `0`, a map beside declared `properties` or beside a `query` parameter included. A declared name the
  guard cannot spare is still reported as `Partial` and still exits `2`, and so does a schema used in a request and in a
  response, for the response's own finding that its DTO carries the declared properties only. This is a breaking change:
  every move on the exit-code axis is major, in either direction, so a CI step that expected `2` here will see `0`.
  Update what it expects. This ships [#169](https://github.com/Gcob/lara-spec-first/issues/169).
- **A map property is now typed by its values on the input DTO.** An object property that declares no `properties` and
  writes a schema under `additionalProperties` was `array<string, mixed>` with its values as they arrived, and is now
  `array<array-key, T>`, `T` being what the value schema becomes: a map of `$ref` values holds DTO objects, a map of
  integers holds `int`s (a form delivers them as text, and they are cast), a map of dates holds `CarbonImmutable`s, and
  `toArray()` writes each one back. An inline object value is its own class, named after the property with `Value`
  (`CreateThingLabelsValueInputDto`). The keys are kept, and the key type is `array-key` for every map, the open ones
  too, since PHP reads the key `"2024"` as the integer `2024`. A map that writes no value schema, or
  `additionalProperties: {}`, is `array<array-key, mixed>`, as the request judges nothing about its values. This is a
  breaking change: `$dto->labels['x']['name']` on a map of `$ref` values throws, and becomes `$dto->labels['x']->name`.
  Read the value as the class it is.
- **The input DTO of a map or open-object body now carries its keys under `additionalProperties`, and `validated()` now
  returns every body key for such a root.** A body that declares no `properties` (a map, `{type: object}` or
  `additionalProperties: {}`) had an input DTO with no property and a `validated()` that returned `[]`. Its DTO now has
  one property, `additionalProperties`, typed `array<array-key, T>` and never `Optional` (`array<array-key, int>` for a
  map of integers, `array<array-key, mixed>` for an open object, an absent or empty body is `[]`), the same in the
  partial class, and `toArray()` returns it. Beside a `query` parameter the values are typed the same way
  (`array<array-key, int>` beside `?dryRun=`), and a map of objects there has its value class. `dto()` leaves the keys
  of the `query` parameters out of it, a body key named like a `query` parameter is read from the query string and the
  generated file says so, and so does it for a key a form delivers under a rewritten name (`a.b` as `a_b`). A root map
  keyed by IDs (`{"1042": 3, "877": 1}`) keeps its keys in `validated()` and on the DTO, and has their values judged:
  Laravel's wildcard alone would drop an integer-like key, so the generated `rules()` writes the rules again under each
  one. This is a breaking change: the class gains a property, so a call to its constructor or a check that it has none
  fails, and a controller that passed `validated()` to `create()` now passes the body where it passed `[]`. Read the
  keys from `$request->dto()->additionalProperties`, and pass only what `create()` accepts. The reasoning is in
  [ADR 0015](./docs/project/decisions/0015-type-the-values-of-a-map-body.md). This closes the gap the `0.2.0`
  `Known limits` lists for [#109](https://github.com/Gcob/lara-spec-first/issues/109). This ships
  [#169](https://github.com/Gcob/lara-spec-first/issues/169).
- **`spec:doctor` compares a part's `encoding` with the default of the document's own OpenAPI version, so its exit code
  can rise from `0` to `2`.** A `contentType` written under `encoding` on a part the request does not read as a file is
  reported unless it is that part's default, and the default was the union of every version's. Each minor is now read by
  its latest patch text (3.0.x as 3.0.4, 3.1.x as 3.1.1, so a 3.1.0 document gets 3.1.1's defaults), since a patch
  corrects the text of its minor and the specification says tooling should not consider it. The doctor now exits `2`
  where it exited `0` for `application/octet-stream` on a number, integer or boolean part, or an array of them, at 3.0
  and at 3.1 (3.1.0's default for a primitive, which 3.1.1 corrected to `text/plain`), and for a plain string at 3.0. A
  part the doctor already reported (for `format` or `contentMediaType`, so the exit stays `2`) gains an `encoding`
  finding: `text/plain` on a 3.0 `format: binary` part of a form-encoded or mixed body, typed or not, and on a 3.0
  `format: byte` string; `application/octet-stream` on a 3.1 `type: string` file part of a form-encoded or mixed body;
  and `text/plain` on a file part with no `type`, at either version. A `RequestBody` built by hand (`new RequestBody`
  with no table) keeps the widest defaults, except for a file part with no `type`, which reports `text/plain`. A 3.1
  string reports neither type until its `contentEncoding` is read
  ([#146](https://github.com/Gcob/lara-spec-first/issues/146)): 3.1.1 gives a string with a `contentEncoding`
  `application/octet-stream`, and the schema does not carry the keyword, so such a string cannot be told from a plain
  one. `application/octet-stream` on a 3.1 string, a `format: byte` one included, stays unreported, as before. Nothing a
  request answers changes: no rule changes, only a finding and the doctor's exit. This is a breaking change: every move
  on the exit-code axis is major, in either direction, so a green CI step that expected `0` here can turn red. Write the
  default of the document's version, or drop the `encoding` entry. The reasoning is in
  [ADR 0005](./docs/project/decisions/0005-read-default-content-type-by-latest-patch.md). This closes the `0.2.0`
  `Known limits` item for [#131](https://github.com/Gcob/lara-spec-first/issues/131).
- **`spec:doctor` names what a response DTO cannot type, and exits `2` on it.** It used to read no response keyword: a
  `oneOf` in a response schema was one `Deferred` count of response declarations, and the exit stayed `0`. Now each
  operation gets one `Ignored` finding per keyword in the JSON schemas of its responses that got a DTO, listing every
  position it is written at, in the same section as a request's: `oneOf`, `anyOf`, `not`, `if`, `then`, `else`,
  `discriminator`, `patternProperties`, `prefixItems`, `unevaluatedProperties`, `unevaluatedItems` and
  `dependentSchemas`. A keyword written in the request and in a response of one operation is one finding. A property a
  response DTO types `mixed` for what the contract says, or does not carry, is named too, as one `Partial` finding per
  reason and per operation under every operation that reaches its DTO: a union of types, no type or only `null`, a
  recursion with no class to point at, properties written beside a reference a recursion points back at, a target that
  is not an object with properties, and the values of `additionalProperties` beside declared properties. A value that
  writes a keyword above is named by that keyword once. An annotation (`default`, `deprecated`) and a value constraint
  (`minProperties`, `contains`) written in a response are not named, and neither is a schema at fault, which gets no DTO
  and says why in the generated file. The responses no longer print a `Deferred` line, and `Support findings` reads the
  response from the plan the build makes, so a document the build plan refuses (a body and a query parameter naming one
  field, or a refused `generated.namespace`) names the request's keywords only, and a note on `Support findings` says
  the response was left out. This is a breaking change: a contract that exited `0` can exit `2`, so a green CI step may
  turn red. Remove the keyword from the response schema, or let the CI step that runs `spec:doctor` accept exit `2`
  until the acknowledgement config is built. This ships [#164](https://github.com/Gcob/lara-spec-first/issues/164).
- **A list with `uniqueItems` now compares numbers by value, so two equal numbers are refused where they passed.**
  `[1, 1.0]`, `[10, 1e1]` or `[0, -0.0]` in a JSON list of numbers, `1` and `+1` or `0` and `-0` in a form list of
  integers, and `1` and `1.0` or `10` and `1e1` in a form list of numbers each got a `200` and now get a `422` with the
  message on both keys, since JSON Schema reads each pair as one number. The rule is Laravel's `distinct` for an integer
  or a number and `distinct:strict` for a string or a boolean, which keep their strict comparison (`"1"` and `"01"` are
  two elements). Two numbers PHP cannot tell apart past float precision also count as one (`9007199254740993` and
  `9007199254740992.0`): the guide states that limit and the generated file carries no finding for it. An array query
  parameter has no element rule, so it does not change. This is a breaking change: a list a client could send twice the
  same number in now gets a `422`. The reasoning is in
  [ADR 0016](./docs/project/decisions/0016-keep-json-scalars-typed.md), and this ships
  [#173](https://github.com/Gcob/lara-spec-first/issues/173).
- **A JSON integer now accepts a whole number written as a float (`1.0`, `1e2`, `1e14`), where `0.2.0` answered `422`.**
  A generated request judges it with `Gcob\LaraSpecFirst\Validation\WholeNumber`, a rule of the package's, beside
  Laravel's `numeric`: it passes an integer and a float whose value is exactly whole, and refuses a fraction, a float a
  hair off a whole number (`2.9999999999999996`, which Laravel's own `integer` reads as `3`), a numeric string (`"42"`),
  a boolean, and a float outside the range of a 64-bit integer. `"42"` and `1.5` still read "must be an integer", and a
  value that is no number at all (`true`, `false`, `"abc"`, `[]`) now gets "must be a number" as well. `validated()`
  holds the float as sent (`31.0`) and `dto()` casts it to an `int`. The finding that said the request refused `1.0` is
  gone from the generated file, since a JSON integer is now judged by its exact value. The Laravel constraint changes in
  another entry, on the Laravel floor. This is a breaking change: code that reads an integer from `validated()` can get
  a float, a project's `validation.custom.<field>.integer` message no longer applies to a JSON integer (`WholeNumber`
  answers with `validation.integer`), and a generated request imports a new class, so run `spec:build` again (a request
  generated before still refuses `1.0`). The reasoning is in
  [ADR 0016](./docs/project/decisions/0016-keep-json-scalars-typed.md), which supersedes ADR 0007, and this also ships
  [#173](https://github.com/Gcob/lara-spec-first/issues/173).
- **Laravel 12.55 is the new floor, and CI installs each floor exactly.** `illuminate/routing` and `illuminate/support`
  need `^12.55 || ^13.0`, where they were `^12.22 || ^13.0`. 12.22 was never tested: the test harness cannot install a
  Laravel 12 older than 12.55, and no generated rule needs anything older. CI's lowest-dependencies run now has one leg
  per Laravel major, installs Laravel 12.55.0 and 13.0.0, and fails when it gets any other version, and a unit test in
  CI's `static` job fails when `composer.json` and the workflow disagree on a floor. Composer 2.9 and newer refuses, by
  default, a Laravel with a known security advisory, which today means anything below 12.71 or from 13.0 to 13.39: that
  is a reason to upgrade Laravel, not a limit of this package, and the package's own check turns Composer's security
  blocking off for that install only. This is a breaking change: an application on Laravel 12.22 to 12.54 cannot install
  this version, so upgrade Laravel first. The reasoning is in
  [ADR 0018](./docs/project/decisions/0018-test-the-declared-laravel-floor.md), and this ships
  [#188](https://github.com/Gcob/lara-spec-first/issues/188).
- **An integer of a body that is JSON and a form now judges a JSON client's number by its exact value, so a float a hair
  off a whole number and `true` are refused, and a whole float of 1e14 or more passes.** A generated request gives such
  an integer `new WholeNumber(acceptsText: true)` beside Laravel's `numeric`, where it gave Laravel's `integer`, which
  reads a float as text with 14 significant digits: a JSON client's `2.9999999999999996` passed as `3` and `dto()` cast
  it to `2`, `true` passed as `1`, and `1e14` was refused. They now get a `422`, a `422` and a `200`, as JSON Schema
  reads them. A form client's text is judged as before (`42` and `+42` pass, `1.0` does not), and a JSON client's `"42"`
  still gets through, which the generated file's finding states. A value that is no number at all (`"abc"`, `true`, and
  the `null` an empty form field becomes) now reads "must be a number" beside "must be an integer", from either client.
  This is a breaking change: a JSON client that sent `true` or a near-whole float for such an integer gets a `422`, a
  project's `validation.custom.<field>.integer` message no longer applies there, and a request generated before keeps
  `integer` until `spec:build` runs again. The reasoning is in
  [ADR 0019](./docs/project/decisions/0019-judge-a-json-integer-by-its-value.md), which supersedes ADR 0016, and this
  ships [#196](https://github.com/Gcob/lara-spec-first/issues/196).
- **A required object `query` parameter written with `form` and `explode: false` now asks for its key, and the findings
  of the array and object parameters that stay unchecked name the serialization they read.** `?color=R,100,G,200` is how
  such an object is sent, under its own name, so it gets the presence rule an array gets (`?color=` still passes, and
  what the object holds is not checked). The generated file now says what each serialization sends where it said that
  `style` and `explode` are not read: `?tag=a&tag=b` for a default array, one string for `explode: false` and the
  delimited styles, one key per property for a default object. A scalar keeps its rules whatever its style. This is a
  breaking change: a request that leaves out such a required key now answers `422`. Send the key, or loosen the
  contract.
- **An object `query` parameter written with `style: deepObject` is now validated, where it was reported and never
  checked.** `?page[size]=10` is judged under `page.size` and a nested object under its brackets
  (`?filter[created][gte]=2020-01-01`), the object is closed when its schema says `additionalProperties: false`, and
  `validated()` holds the value nested and as text. It holds whatever `explode` says: the parser reads `false` into a
  document that writes `style: deepObject` alone, and OpenAPI 3.2.0 gives the field no effect for this style. A request
  that answered `200` and now answers `422` is one the contract refuses: a value of the wrong type or out of bounds, an
  undeclared key of a closed object, a required property left out, or a required parameter that requires a property. A
  required `deepObject` that requires no property a client sends is accepted left out, since an empty object cannot be
  written in a query string. An array inside it is checked the way `query.deep_object_arrays` says (see `Added`): by
  default as a list written with brackets, so `?filter[ids]=1,2` and `?filter[ids]=1`, which `0.2.0` let through, now
  answer `422` unless the key is `comma` or `any`. A property whose name holds a bracket, or a `deepObject` that shares
  its key with another query name, keeps a finding. Every finding names a property the way a client writes it
  (`filter[active]`), where it named the dotted rule key. This is a breaking change: send the value the contract
  describes, loosen the contract, or set `query.deep_object_arrays` to the way your clients write an array. It closes
  the `0.2.0` `Known limits` item for [#139](https://github.com/Gcob/lara-spec-first/issues/139), and the reasoning is
  in [ADR 0022](./docs/project/decisions/0022-read-a-deep-object-query-by-its-brackets.md) for the object and
  [ADR 0023](./docs/project/decisions/0023-read-an-array-in-a-deep-object-as-the-app-declares.md) for the array.
- **`spec:doctor`'s exit code moves for a `query` parameter, in three directions.** From `0` to `2`: a keyword inside a
  `deepObject` that no rule enforces (a property's `format: uri-reference`, an item's too inside an array the key
  checks) is now named, where the whole object was silent. From `2` to `0`: a `deepObject` map's `additionalProperties`,
  which the request validates now, is no longer named. From `0` to `1`: a `style` that is not one of the four OpenAPI
  defines for a `query` (`form`, `spaceDelimited`, `pipeDelimited`, `deepObject`), or an `explode` that is not a
  boolean, is now a fault in the document. It was read by nothing and reported nowhere: the contract carries both as
  written, the generated file says what is wrong, and the parameter is read as if the keyword were not written. This is
  a breaking change: every move on the exit-code axis is major, so a CI step that expected the old code will see the new
  one.
- **The build refuses a `query` parameter named like any top-level name the body's schema writes, where it refused only
  a name that had a rule.** A name the body writes without a rule of its own slipped through: a name in `required` that
  `properties` does not declare, in a body that is optional, that may be `null`, that is read as a form, or that a
  `PATCH` sends (whose required list is read as partial by default), a name an `allOf` branch requires, and a
  `dependentRequired` name `properties` does not declare. The request reads such a key from the query string and drops
  the body's copy, so the query string could fill, or hide, a key the body's contract states. `spec:build` and
  `spec:make` now stop with a message that names the body's name and says the request drops the body's copy, and
  `spec:doctor` reports it under `Routing outcome`. Its exit code can rise from `0` to `2`, and from `1` to `2` when the
  contract also had a document fault the refused rule set carried (the doctor leaves that fault out of the report while
  the build refuses the operation). A body key the schema does not name (an open map's) is still read from the query
  string, with its finding. Only a body read as an object counts: an array body, or a typeless one that names no
  `properties`, still builds. This is a breaking change: a contract that built is refused, and every move on the
  exit-code axis is major, so a green CI step can turn red. Rename the `query` parameter or the body's name. This ships
  [#158](https://github.com/Gcob/lara-spec-first/issues/158).
- **`spec:doctor` now names what a field the generated request drops whole states, and its exit code can rise from `0`
  to `2`.** A form field PHP delivers under no name or under another's key, and the form side of a name PHP rewrites in
  a body that is JSON and a form, were told in the generated file's findings only. The doctor now reports each one's
  `type`, its `required` and every keyword it states, one finding per keyword and per operation, and reports
  `dependentRequired` on each name a trigger with a `*` or with no rule no longer asks for, except a `readOnly` name and
  one the object already requires, which a trigger the request keeps does not ask for either. A field the request drops
  and the doctor already reported (a `*` name, a `query` name PHP appends to or delivers under no name) gains its
  `type`. A `*` name loses its `required` when it is `readOnly` or a top-level name of a `PATCH`, as it does for a field
  the request keeps (a `query` name keeps its `required`): one that was only that no longer counts, so a contract that
  exited `2` for it can exit `0`. It also names what no Laravel rule can hold, on the field that states it: a required
  name with a dot or a comma (`required`), a closed object that declares such a name, or a closed form body that accepts
  the key a rewritten name is delivered at (`additionalProperties`), a field of more than one type or typed only `null`,
  and the integer, number and boolean of a body that is JSON and a form (`type`), a field with no type and an `enum` or
  a `const` in a JSON body, or holding a boolean or a `null` in a query or a form (`enum`), and a recursion through a
  schema with no name (`$ref`). It names what a body or a `query` parameter the request reads only in part states: a
  body in a media type this package does not read (`content`, on the media type: beside JSON or a form, the generated
  request judges it by their rules on an empty body, so a required field answers `422`, and the doctor says so), a body
  whose root is not an object (`type`, what it states, and `required` for a required body), the names an optional form
  body asks only beside another key (`required`), a `query` array or object whose serialization the request does not
  read (`type` and what it states, and `required` for an object whose own name is not sent), an array inside a
  `deepObject` under `query.deep_object_arrays: any` (`type` and each of `items`, `minItems`, `maxItems` and
  `uniqueItems` it states), and a `deepObject` property named with a bracket, with the closed object around it, which no
  longer refuses undeclared keys (`additionalProperties`). A keyword the package does not read on a field with no type
  is still reported once, as `Ignored`, as it was. The generated file's findings are the same, except that a `*` trigger
  no longer says its `*` twice, and says what it no longer asks for. This is a breaking change: a contract that exited
  `0` can exit `2`, so a green CI step may turn red. Fix the contract where the finding says how, or let the CI step
  accept exit `2` until the acknowledgement config is built. The reasoning, and what counts as a gap and what as a
  standard reading, is in [ADR 0024](./docs/project/decisions/0024-hold-rules-and-report-every-gap.md). This ships
  [#119](https://github.com/Gcob/lara-spec-first/issues/119) and closes its `0.2.0` `Known limits` item.

### Fixed

- **`spec:build` no longer counts a finding that loses no constraint in its unenforced-constraint warning.** A
  `readOnly` property that is `required`, a body declared under two media types over one schema, the optional fields of
  a `PUT`, a name the body requires without declaring it, and a field that states no type and nothing else, or only a
  `default`, `deprecated`, `$defs` or `$anchor`, are still said in the generated file's Findings block, and a request
  with only these gains the "Nothing was read and left unenforced" line it did not have. So is a field the request drops
  whole (a `*` name, a form name PHP delivers under no name, a `query` name PHP appends to) that states nothing: no
  type, no keyword, and not required, and so is an optional body whose schema is not an object and states nothing
  (`schema: {}`), where a required one still counts through its `required`. So is a boolean read from a query string or
  a form, or an item of a boolean list in the query string, which accepts `"1"` and `"0"` as a client of either sends
  them, and a field with no type whose `enum` or `const` holds numbers or strings, read from a query string or a form,
  where every value is text. So is a `PATCH`'s emptied required list, read as the partial update the method means, and a
  required form body that accepts an empty form, which is how a form writes `{}`. A body that is also multipart with a
  file part, a field typed only `null`, a field that declares more than one type, a field that states no type but writes
  an `enum` or a `const` in a body that is JSON or holding a boolean or a `null`, and a `oneOf` or another keyword the
  package does not read (on the field, through a `$ref` or in an `allOf` branch), still count. This closes the `0.2.0`
  `Known limits` item for [#134](https://github.com/Gcob/lara-spec-first/issues/134).
- **A plain `query` name beside the names PHP nests under it refused a valid request, with no finding.** `?page[size]=3`
  arrives as `page => [size => 3]`, so the `integer` rule of `page` refused a request the contract allows (`422`, now
  `200`), and `?ids[]=1` was judged by the rule of `ids` the same way. `page` beside `page[size]`, `ids` beside `ids[]`,
  `page` beside `page[*]` and `filter[a]` beside `filter[a][b]` now judge the plain name's value only when it is not an
  array, with Laravel's own `Rule::when()`: `?page=abc` and `?page=0` are still `422`, `?page=2` still reaches
  `validated('page')`, and the nested names keep their rules. The array PHP builds is kept out of `validated()` when it
  is unchecked: only what a rule below it validates stays (`page.size`), and beside `ids[]` or `page[*]`, which have no
  rule, `?ids[]=-5&ids[]=abc` answers `200` with no `ids` in `validated()`. Two names that arrive under the same key
  (`user_name` beside `user.name`) cannot be told apart, so they get no rule and the generated file says so in a
  finding. A plain name beside a body key is still refused. See
  [#110](https://github.com/Gcob/lara-spec-first/issues/110).
- **The "a form with a `Content-Type` and no field" finding no longer appears where a required field, or the body's
  required-key check, already refuses an empty form.** A required body read as a form got it even when a required
  property, a required file part or a required name `properties` does not declare was already refusing the empty form,
  so the finding named a gap the request did not have. It stays where nothing refuses an empty form (a `PATCH`, a
  `readOnly` or `dependentRequired` key alone, a name PHP rewrites or a collision withholds). Nothing the request
  accepts or refuses changes: only the generated file's findings do.
- **A body read only as a form that declared `filter[a]` and `filter[a][b]` refused a valid request, with no finding.**
  PHP delivers `filter[a][b]=1` as `filter => [a => [b => 1]]`, so `filter.a` became an array and the `string` rule of
  `filter[a]` refused it (a closed body refused a valid `filter[a]=x` instead, since the level above `filter.a.b` was
  closed to `b`). Both names now keep their rules, `filter[a]` behind a shape check, so `filter[a][b]=1` passes and
  `filter[a][b]=x` answers `422`, and the closed body still refuses `filter[evil]=1`, `filter=abc` and
  `filter[created][lt]=5` while it accepts what the contract declares. A body that is JSON and a form is unchanged. This
  closes the refusal the `0.2.0` `Known limits` lists for [#113](https://github.com/Gcob/lara-spec-first/issues/113),
  and ships [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **An optional `page` beside `page[*]` in an open form body refused `page[*]=5`, with no finding.** `page[*]=5` arrives
  as `page => ['*' => '5']`, an array the `integer` rule of `page` refused. A `*` name has no rule, so the array is the
  shape check's to skip: it now passes, with `page` left out of `validated()`. A closed body keeps the plain rule, which
  still refuses it, since the key check of a shaped `page` could not name the `*` key and would accept `page[evil]=5`. A
  `page` the contract asks for (required, or a `dependentRequired` dependent) still refuses it, and the generated file
  now says that a valid `page=2&page[*]=5` is refused as well, since PHP keeps the later name: that refusal is not new,
  and now has its finding. A `PATCH` asks for no presence through `required`, so in an open body a required `page` there
  is shaped like an optional one and passes both. This ships [#168](https://github.com/Gcob/lara-spec-first/issues/168).
- **`spec:make` plans the build before it writes anything.** A document the build refuses (a path parameter named
  `request`, `this` or `2fa`, two operations claiming one request name, a body property and a query parameter with one
  name) used to leave a child controller that did not compile, which the command then never overwrote. It is now refused
  first, with the build's own message and exit status, and nothing is written: no class, and no line in the
  specification. A name typed at the `x-controller` offer is planned too, so one the build would refuse leaves the
  specification as it was. This closes the first half of the `0.2.0` `Known limits` item for
  [#112](https://github.com/Gcob/lara-spec-first/issues/112).
- **A required body that allows `null` and requires a property accepts a JSON `null`, where it answered 422.** The body
  is read as JSON (JSON only, or JSON and a form) and is not a `PATCH`. A JSON `null` reaches the rules as `[]`, so the
  `present` on each required key refused a payload the contract allows. Each required key now asks through its siblings
  (`present_with`), as an optional body's do, and the generated request still refuses `{}` and every other body the
  contract refused, on the same keys and with the same status, with or without Laravel's `ConvertEmptyStringsToNull`:
  only `null` passes, and `dto()` returns `null` for it. Two messages change, and no status or key does: a key missing
  beside a sent sibling reports `present_with`'s ("must be present when ... is present") where it reported `present` or
  `required`, and an empty or `null` integer, number or boolean reports `filled`'s ("must have a value") where it
  reported `required`'s. A form client of a body that is JSON and a form gets the same statuses and keys. This closes
  the refusal the `0.2.0` `Known limits` lists for [#111](https://github.com/Gcob/lara-spec-first/issues/111).
- **A 3.0 `format: binary` part written `contentType: text/plain` was reported in a `multipart/form-data` body alone and
  read as the default in a form-encoded or mixed one.** 3.0 gives it `application/octet-stream`, so all three bodies now
  report it. See [#131](https://github.com/Gcob/lara-spec-first/issues/131).
- **A JSON number PHP cannot hold, on a field with `multipleOf`, is refused with a `422`, where the request answered
  `500`.** PHP reads `1e400` in a JSON body as `INF`, and Laravel's `multiple_of` throws on it. Every field with
  `multipleOf` now gets the package's `MultipleOf` (`Gcob\LaraSpecFirst\Validation\MultipleOf`) in place of
  `multiple_of`, in a body and in the query, with `multiple_of`'s message, custom messages and `failed()` entry. Such a
  number is refused whatever the divisor, though the contract accepts some (`1e400` is a multiple of `0.5`): once read,
  its value is gone. A request generated before keeps `multiple_of` until the next `spec:build`. The reasoning is in
  [ADR 0021](./docs/project/decisions/0021-judge-multipleof-from-the-digits.md), and this ships
  [#198](https://github.com/Gcob/lara-spec-first/issues/198).
- **A number written as text, on a field with `multipleOf`, is judged exactly and in milliseconds, where a huge exponent
  or a long decimal took seconds or answered `500`.** Laravel's `multiple_of` writes out every digit an exponent stands
  for: under a 128 MB memory limit, `?amount=1e50000000` took over 2 seconds, `1e80000000` exhausted memory, a fatal
  error that answered `500`, and an exponent too large for brick/math answered `500` too. Without the GMP and BCMath
  extensions, an 8 KB decimal took several seconds. `MultipleOf` now decides from the number's digits and exponent, with
  PHP integers, in time that grows with the length of the text and never with its exponent, and answers every other
  value as `multiple_of` does, except a JSON float of more than 14 significant digits, which it judges as the double PHP
  reads (`0.30000000000000004` is no multiple of `0.01`, where `multiple_of` rounds it to `0.3` and passes it, by the
  server's `precision` setting). So `1e99999999999999999999` is a multiple of 2, and passes. The reasoning is in
  [ADR 0021](./docs/project/decisions/0021-judge-multipleof-from-the-digits.md), and this ships
  [#214](https://github.com/Gcob/lara-spec-first/issues/214).

## [0.2.0] - 2026-11-15

The first release that reads a schema. `spec:build` turns the request body and the `query` parameters of each operation
into a `FormRequest` and an input DTO, and `spec:doctor` reports what those requests cannot enforce. `0.1.0` read no
schema, so most of what follows is new, and [Upgrading from 0.1](#upgrading-from-01) says what an existing application
changes.

### Upgrading from 0.1

A `^0.1` constraint does not let `composer update` move to `0.2`, so run `composer require gcob/lara-spec-first:^0.2`
(on Laravel 12.0 to 12.21, [upgrade Laravel first](#the-support-range)), then `php artisan spec:build`, then
`php artisan spec:doctor`. Every item below says what you see and what you change. A refusal stops the whole build and
leaves the generated tree untouched, even when one operation is at fault. `spec:doctor` reports every refusal too:
`Routing outcome` and `Support findings` give exit `2`, a document fault gives exit `1`.

#### A name clash

- **A path parameter named `{request}`.** The build refuses an operation that has it in its path and a body or a `query`
  parameter, because the generated `routeAction` declares the request as `$request`. Rename the path parameter.
- **Two operations that generate one request class.** The build says "both generate the request class" when the
  `operationId` (or the method and path) of two operations reduce to one class name, and `x-controller` does not rename
  a request. Give one of them an `operationId` that does not collide.
- **Two different schemas that generate one input DTO class.** The build says "both generate the input DTO" when two
  files each define a component of one name (`User` in `users.yaml` and in `orders.yaml`), two names reduce to one class
  (`new-user` and `NewUser`), a component `UserPartial` sits beside `User`, or the inline body of `createUser` sits
  beside a component named `CreateUser`. Rename one of the schemas, or give the inline body a component name of its own.
- **A schema name that leaves no class name.** Once every character that is not a letter, a digit or `_` is dropped, a
  name such as `123`, `2fa` or `---` does not start with a letter. Rename the schema.
- **Two keys of one body schema that derive one PHP property.** The build says "both derive the property name" for
  `first-name` and `first_name`, or `a.b` and `a_b`. Rename one of the keys.

#### The body and `query`

- **One name in both the body and `query`.** Laravel merges the two into one value, so the build refuses an operation
  that declares `email` as a body property and as a `query` parameter, and a form body name PHP rewrites into a `query`
  parameter's name (`user.name` arrives as `user_name`). Rename one of the two.
- **An `allOf` whose branches disagree**, at 3.0 and at 3.1, on a request body or a `query` parameter (never a
  response). The build refuses two branches that share no type or no `enum` value, two that write a different `format`,
  `pattern`, `multipleOf` or `contentMediaType`, and `additionalProperties: false` beside a branch that declares a
  property the closed one does not (`allOf: [{$ref: Base}, {properties: {...}, additionalProperties: false}]` reads as
  "none of `Base`'s properties allowed"). At 3.1 the keywords beside a `$ref` are one more branch. Make the branches
  agree, and write `additionalProperties: false` on the schema that declares every property.

#### Media types

- **Two different schemas under two media types.** The build says "cannot hold two rule sets" on an operation whose body
  declares two of `application/json`, `multipart/form-data` and `application/x-www-form-urlencoded` over schemas that
  differ in anything, including two components with the same content under two names, or two copies that differ only in
  an `example`. A media type the package does not read (`application/xml`) is reported, not refused. Write one `$ref`
  under every media type, or split the operation.
- **One schema with a file part, sent as multipart by one operation and as another media type by another.** The build
  says "cannot be both": the part is an `UploadedFile` in one and a string in the other, and they share one input DTO.
  Give the multipart operation a schema of its own.

#### References and schema keywords

The first two apply to every schema an operation reaches, a response schema included, and the third to the whole
document.

- **`$id` and the dynamic-scope keywords.** A `Rejected` refusal on a schema that writes `$id`, `$dynamicRef`,
  `$dynamicAnchor`, `$recursiveRef` or `$recursiveAnchor`, and at 3.1 beside a `$ref`. Remove `$id`, write each
  reference relative to the document or its file, and replace a dynamic reference with an ordinary `$ref` (a tree that
  refers to itself is supported).
- **A raw keyword that holds a `$ref`.** A `Rejected` refusal on `prefixItems`, `contains`, `unevaluatedItems`,
  `patternProperties`, `propertyNames`, `dependentSchemas`, `unevaluatedProperties`, `contentSchema`, `if`, `then` or
  `else` with a `$ref` inside it, and at 3.1 beside a `$ref`. The same keyword holding inline schemas is still ignored,
  and a `$ref` inside `example`, `examples`, `default`, `enum` or `const` stays a literal. Inline the schema, or drop
  the keyword.
- **A `$ref` into a `$defs`, anywhere in the document.** A `Rejected` refusal for a reference such as
  `#/components/schemas/Money/$defs/Amount`, even in a schema no operation reaches. Move the definition under
  `components.schemas`.
- **A broken reference or keyword beside a 3.1 `$ref`.** A document fault (exit `1`) for the operation that reaches it:
  a `$ref` written inside the keywords beside a `$ref` whose target is missing, or whose chain comes back through other
  files; a target the parser cannot build (`items: "x"` in the file it names); a malformed keyword
  (`{$ref: Code, items: "x"}`). `0.1.0` dropped every keyword beside a `$ref` unread. Fix the reference or the keyword.
- **An `allOf` written as a mapping**, at 3.0 and 3.1: `allOf: {$ref: X}` is a document fault on any schema an operation
  reaches. Write it as a list, `allOf: [{$ref: X}]`.
- **A pure reference cycle inside the keywords beside a 3.1 `$ref`.** The cycle refusal (`References`, exit `1`) now
  also covers a chain of references written beside a `$ref` that never reaches content, and it refuses the whole
  document. Make one reference of the chain point at a schema.
- **An `x-max-depth` that is not a whole number of zero or more.** A document fault where it is written. Only a contract
  that already used that extension name for something else can meet it. Remove or correct the value.

#### The support range

- **Laravel 12.0 to 12.21 cannot install this version.** Composer refuses the update: `illuminate/routing` and
  `illuminate/support` need `^12.22 || ^13.0` (they were `^12.0 || ^13.0`). PHP stays `^8.3`. Upgrade Laravel to 12.22
  or newer first.

#### `spec:doctor`'s exit code

A run that was green on `0.1.0` may be red. Drift is exit `1`. The four after it (`Ignored`, `Partial`, a dropped field,
`header` and `cookie`) are package limits: exit `2`, never `1`. A malformed `encoding` is the one case that exits `1`.
If a CI step runs the doctor, it is the step that changes.

- **Drift until `spec:build` runs again.** Right after `composer update`, `Drift` findings (exit `1`) appear for each
  operation with a body or a `query` parameter (a new request class, input DTOs, a new `routeAction` signature) and for
  each operation with `x-controller` (the parent's `@see` to the child is now written in backticks). Run `spec:build`,
  then commit the generated tree if your application commits it.
- **An `Ignored` keyword in a request schema.** `Support findings` names `default`, `deprecated`, `oneOf`, `anyOf`,
  `not`, `discriminator`, `minProperties`, `prefixItems` and the rest, per operation and with every position, in the
  body and the `query` parameters (not a response). At 3.1 it names the same keywords written beside a `$ref`, and an
  `x-max-depth` there. `default` and `deprecated` alone appear in most published contracts. Nothing changes in the
  application: the CI step that runs `spec:doctor` accepts exit `2`, or waits for the acknowledgement config, which is
  not built yet. Move an `x-max-depth` onto the recursive component.
- **A `Partial` keyword the request cannot fully enforce.** A finding names the keyword and the fields: a `format` no
  Laravel rule matches (`uri`, `float`, `byte`, `duration`), a `pattern` across the ECMA-262 / PCRE boundary, a keyword
  the matrix rates `Supported` that the rule set left out on some fields. `int32` (enforced as its bounds), `int64`,
  `double` and `password` do not gate. Handle it as the item above.
- **The constraints of a field the request drops.** A finding on a `query` parameter whose name PHP rewrites
  (`page[size]`, a name with a dot or a space), or on a body key with `*`, names the `required` and the bounds the
  request cannot enforce. Rename the parameter or the key if your application owns the contract, and otherwise handle it
  as the item above.
- **A `header` or `cookie` parameter.** They move from the shared `Deferred` count, which never gated, to `Ignored`: the
  package will not validate them. The `Deferred` lines for `query` parameters and `requestBody` are gone, since both are
  read now. Handle it as the item above, and validate headers and cookies in your application.
- **An `encoding` the request cannot honor.** Exit `2` for an `encoding.<part>.contentType` on a part that is not read
  as a file (unless it is the default OpenAPI gives that part) and for a text type on a file part. Exit `1`, a document
  fault, for a `contentType` that is not a string and for an `encoding` entry for a part the body does not declare.
  `0.1.0` never read `encoding`. Fix a malformed `encoding`, and handle the rest as the item above.

#### What your clients see

- **A generated route validates its request.** An invalid body or `query` is refused before the controller, where
  `0.1.0` passed everything through. Laravel answers a failed `FormRequest` with a `422` and the errors as JSON when the
  client asks for JSON (`Accept: application/json`), and with a `302` redirect back, without the errors (the generated
  routes run no session middleware), to any other client
  ([how to make every refusal a JSON `422`](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).
  The new refusals are the types and constraints of the mapping table; a required key as `present` (`required` only for
  an integer, a number or a boolean); a `PATCH` or an optional body with every key optional; a file part as `file` with
  `mimetypes:` and `max:`; at 3.1 the keywords written beside a `$ref` (`{$ref: Code, maxLength: 3}` applies both); and
  a body field read from the body only, so a required body key sent only in the query string is refused. Fix the
  clients, or loosen the contract where it is stricter than what the application accepts. A client that wants the `422`
  sends `Accept: application/json`.
- **A recursive payload deeper than its depth.** A recursive schema (an `Address` holding an `Address`, a tree) is
  validated level by level down to `x-max-depth` (3 when the schema states none), and one level below it the field must
  be absent, or `null` when the schema is nullable (an empty list passes). A deeper payload is refused, which is
  stricter than the contract, and the generated request says so in a finding. A schema too big to unroll within 1,000
  rule keys gets its depth lowered, with a finding that names the depth that applies. Set `x-max-depth` on the recursive
  component to the depth your application really accepts.
- **A JSON body refuses what Laravel's loose rules coerce.** On an operation whose body is `application/json` alone,
  `"42"`, `true` or `1.0` for an `integer`, `"1.5"` for a `number`, and `1`, `0`, `"1"` or `"0"` for a `boolean` are
  refused at every level, with `integer:strict`, `numeric:strict` and `boolean:strict`. A form client posting to a
  JSON-only operation (`age=30` as `application/x-www-form-urlencoded`) is refused the same way. A `query` parameter, a
  form or multipart field, and a body declared as JSON and a form at once keep the loose rules. Fix the clients that
  send numbers or booleans as strings, and declare `application/x-www-form-urlencoded` on an operation that really takes
  a form.

#### Generated code you own

- **A custom controller for an operation with a body or a `query` parameter stops loading.** After `spec:build`, the
  generated parent's `routeAction()` takes the generated request first
  (`routeAction(CreateUserRequest $request, string $id)`), so a child written on `0.1.0` (`routeAction(string $id)`) is
  no longer compatible: PHP raises a fatal when the class loads, on the first request to that route, in a test that hits
  it, or in static analysis. `spec:build` does not warn. Edit each such child by hand: import the request class (under
  the configured `generated.namespace`), add it as the first parameter of `routeAction()`, and pass it on in
  `parent::routeAction($request, $id)`. An operation with neither a body nor a `query` parameter has no request, and its
  child is unchanged.

### Added

- **`spec:build` emits one `FormRequest` per operation that states anything about its input**, into the `Requests`
  sub-namespace of the generated tree, pruned like every other generated file. The rule set is the request body and the
  `query` parameters merged by field name: a body field is read from the body (the JSON bag, or the form bag with the
  uploads) and only the declared `query` parameters from the query string, so the URL cannot satisfy a body field and
  the body cannot satisfy a `query` parameter. The scalar types, nullability and presence are mapped, and every keyword
  the build does not translate is named per field in the generated file's own findings rather than dropped. An operation
  with no body and no `query` parameter gets no class, and the build says how many. **A request that fails validation is
  refused before the controller**: Laravel answers `422` with the errors as JSON to a client that asks for JSON, and
  redirects any other client back, without the errors, since the generated routes run no session middleware
  ([what a client gets](./docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).
  The build's "read a constraint they do not enforce" warning counts the requests that read something and enforce
  nothing for it, and one case that is only a gap, a body that is JSON and a form at once. A finding that says the
  request is stricter than the contract (an integral float refused by `integer:strict`, a `mimetypes` rule refusing a
  file named like PHP, a recursion refused below its depth, a body with `properties` and no `type` refusing a JSON root
  that is not an object) stays in the generated file's Findings block and out of the count, and a request with only
  those keeps its "Nothing was read and left unenforced" line.
- **The whole mapping table is built.** String and number bounds, `multipleOf`, the `format` values Laravel has a rule
  of the same meaning for (`date`, `date-time` as the RFC 3339 grammar, a fraction of any length included, `email`,
  `uuid`, `ipv4`, `ipv6`), `enum` and `const` as `Rule::in()` over an array, `pattern` as `regex:` inside the ECMA-262 /
  PCRE boundary, arrays with their element rules under `field.*`, nested objects under dotted keys, `allOf` merged,
  `dependentRequired`, an optional body's "all or none", and `additionalProperties: false` at every depth. A required
  key gets `present`, and `required` only for an integer, a number or a boolean: JSON Schema's `required` asks for the
  key, and Laravel's refuses `null`, `""`, `[]` and `{}`, which the contract may allow. What stays outside the boundary
  is named in the generated file's findings. Most of the support matrix's schema rows are `Supported`, and `format`,
  `pattern` and `uniqueItems` are `Partial`. Two reads stand out:
    - **A body that is JSON and nothing else keeps its types**, with Laravel's `integer:strict`, `numeric:strict` and
      `boolean:strict` at every level. `1.0` is the one a JSON Schema validator accepts: PHP decodes it as a float,
      `is_int()` cannot tell it from `1.5`, and the generated file says it is stricter than the contract in one finding
      per request. A form client of a JSON-only operation is off-contract and gets the same rules. A `query` parameter,
      a form-encoded or multipart field, and a body that is JSON and a form at once keep the loose rules, since one rule
      set cannot read a form's text and refuse a JSON string. That last body has one finding per integer, number and
      boolean field naming what a JSON client gets through (`"42"` and `true` for an integer, `"1.5"` for a number,
      `"1"` and `1` for a boolean, whose finding also says a form's `"true"` is refused).
      [Request validation](./docs/guide/code-generation/request-validation.md#a-json-body-keeps-its-types).
    - **A keyword written beside a `$ref` in a 3.1 schema is read.** JSON Schema 2020-12 applies
      `{$ref: Code, maxLength: 3}` as `Code` and a ceiling of three, in a request and in a response schema alike: it is
      read from the raw document at every hop of an alias chain, and the field is one `allOf` wrapper (the referenced
      schema, then what is written beside the `$ref`). A `$ref` written inside those keywords
      (`{$ref: Base, properties: {owner: {$ref: User}}}`, `items` and an `allOf` branch included, at any depth) is
      followed: `owner` has the rules of `User`, and so does what `User` writes below it. A relative path is resolved
      against the file that wrote it, and a recursion that closes through the keywords ends in a recursion marker the
      first time round. A keyword that adds nothing a generator reads (`description`, `title`, `deprecated`, `default`)
      leaves the field as the schema it names, and a DTO keeps that schema's class: `Alias: {$ref: Node, maxLength: 3}`
      is still `NodeInputDto`, and one that writes a `required` or `properties` is a class of its own. A 3.0 document is
      unchanged, since its specification ignores them.
      [Keywords written beside a `$ref`](./docs/guide/openapi-support.md#keywords-written-beside-a-ref).
- **A file part is validated as one.** In a body that is `multipart/form-data` alone, a property the schema calls a file
  (`format: binary` at 3.0, `contentMediaType` at 3.1) becomes `file`, `mimetypes:` from `contentMediaType`, and `max:`
  in kilobytes from `maxLength`, as an exact decimal (`max:2.44140625` is 2500 bytes, which Laravel's `max` takes).
  `application/octet-stream` and `*/*` add no `mimetypes`, which would refuse every upload but an octet stream. A
  required part is `required`, so an empty upload slot is refused. Beside another media type, or in a JSON body, the
  part is a string. A closed root also refuses an undeclared upload. `contentMediaType` is `Partial` in the support
  matrix: it is read for what makes a part a file and for the types `mimetypes:` carries.
- **`encoding.<part>.contentType` narrows a file part** ([#90](https://github.com/Gcob/lara-spec-first/issues/90)). The
  contract carries it as written, and the generated request turns the list into `mimetypes:` on a top-level part of a
  `multipart/form-data` body, and on `part.*` for an array of files, ahead of `contentMediaType` and even when it is
  looser. A part with `encoding.<part>.contentType: image/png, image/jpeg` answers `422` to any other type, a 3.0
  contract included, which has no `contentMediaType`. Each entry is cut at its `;`, trimmed, lower-cased and
  de-duplicated, wildcards kept. `application/octet-stream` or `*/*` in the list adds no `mimetypes`, and a named text
  type adds none and is reported (`text/*` keeps its rule, which Laravel matches on the first half of the sniffed type).
  A `contentType` on a part that is not read as a file (a form-encoded body is only reported, never narrowed), other
  than a default OpenAPI gives that part, is reported. A `contentType` that is not a string (a YAML list) and a part the
  body does not declare are faults in the document, in the generated file and in `spec:doctor`, which exits `1` for
  them. `encoding` beside `application/json` is ignored, and `headers`, `style`, `explode` and `allowReserved` are still
  not read. `Media type encoding` is `Partial` in the support matrix. What the doctor does about it is under `Changed`.
- **A recursive request schema is validated down to a depth, and `x-max-depth` sets it.** A schema that refers to itself
  (a tree, a comment thread, an `Address` holding an `Address`) is unrolled into the depth the schema states with
  `x-max-depth` (three when it states none): every level is validated, and the field below the last one must be absent,
  or `null` when the schema is nullable, and an empty value there is refused otherwise. A payload nested deeper than the
  depth is a `422`, which is stricter than the contract and which the generated file says in a finding that names
  `x-max-depth`. A contract whose recursion is deeper than three levels in real traffic states the depth it needs. The
  rule set grows with the depth: a schema that recurses once per level gets one more copy of its rules for each level,
  and a schema that recurses more than once per level gets that many times more. **Unrolling is capped at 1,000 rule
  keys per operation**: when the stated depths do not fit, they are lowered until they do, the deepest schema first,
  every branch of a schema is cut at its own level, and a finding names for each lowered schema the stated depth, the
  depth that applies and `x-max-depth`, so a build never runs out of memory on a branching schema. An empty list of the
  recursive schema passes at the cut, and a required field that cannot be `null` keeps its presence beside it. An
  `allOf` around a recursion keeps each constraint at the level that wrote it. `x-max-depth` is the package's first
  schema-level extension: a whole number of zero or more, written on the recursive component and read the same way at
  3.0 and 3.1, and `Contract\Schema` carries it as `maxDepth`.
  [Request validation](./docs/guide/code-generation/request-validation.md#a-recursive-schema-is-validated-to-a-stated-depth).
- **`Gcob\LaraSpecFirst\Validation\NullOrAbsent`** is public API: a rule a generated request imports for a nullable
  recursive field below its depth, which accepts an absent key or `null` and nothing else.
- **`spec:build` writes the input DTOs.** One `final readonly` class per request body that is an object, in the `Data`
  sub-namespace: a promoted constructor, `from()`, `toArray()`, `Arrayable` and `JsonSerializable`. Each body gets a
  full type and a `Partial` one, `NewUserInputDto` and `NewUserPartialInputDto`, named after the schema and never after
  the `$ref` or the file that holds it. A property the client may leave out is `Optional|T`, so `toArray()` never writes
  a `null` over a field that was not sent. Two operations reaching one named schema share its DTO, a 3.0 `$ref` wrapper
  carrying only an `example` or a `readOnly` beside it included; a wrapper that adds `required` or `properties` is a
  schema of its own. A `date-time` is written back by `toArray()` with its fraction (microseconds), a component name
  with dots derives its class name by dropping them, a required key Laravel cannot name is optional in the DTO, an
  enumeration value that could close a docblock is not written there, and a closed root keeps a declared integer key
  such as `"2024"`. **A recursion is typed as its own class**: an `Address` holding an `Address` generates
  `Optional|AddressInputDto $parent` (`Optional|AddressInputDto|null` when the schema is nullable, and
  `?AddressInputDto` for a required key), a list of the schema is a `list` of its class, and a recursive pair is one
  class per schema whichever operation reaches it first. A recursion the request does not unroll (through a schema with
  no name, a field that is two markers, or into a schema that is not an object with properties) is `mixed` and says so
  in the file's findings; so is one with `properties` written beside its reference, which the request validates and the
  ancestor's class cannot carry, and its finding says that instead. The build refuses the names it cannot give a class,
  which is listed under `Changed`.
  [DTO anatomy](./docs/guide/code-generation/dto-anatomy.md#what-each-schema-type-becomes).
- **A generated request has a `dto()` method** returning the input DTO built from `validated()`: the full type for a
  `POST` and a `PUT`, the partial one for a `PATCH` and for a body that may be absent. It is `dto()` and not `data()`
  because `Illuminate\Http\Request` owns a protected `data()` that its typed accessors read through. `validated()` is
  untouched, and a request with no object body has no `dto()`.
- **`Gcob\LaraSpecFirst\Data\Optional`** is public API: the one class of this package a generated DTO imports.
- **`Contract\Schema` and the types around it**: `SchemaType`, `RequestBody`, `QueryParameter` and `Response`. A schema
  reaches every generator normalized and free of any OpenAPI version, so nothing downstream branches on which one the
  document declared. It carries its `name`, what the document calls it (a key of `components.schemas` in whichever file
  holds it, or the file name of a schema that is a whole file, never derived from how the `$ref` reaching it was
  spelled, so splitting a specification across files renames nothing, and null for a schema written inline), its
  `source`, where a named schema is written, and `ignored`, each `Ignored` keyword a node writes and its position, for
  `spec:doctor` to report. A response schema is read the same way as a request schema, the keywords beside a 3.1 `$ref`
  included, although no response DTO exists yet.
- **`Contract\Operation` gains `requestBody`, `queryParameters` and `responses`**, plus `response()` and
  `withController()`.

### Changed

- **Breaking: `spec:build` refuses contracts that `0.1.0` built.** `0.1.0` read no schema, so it built contracts that
  name a class twice, merge two sources into one value or hold a keyword it could not honor. Each is now refused, the
  build stops at the first fault and the generated tree is left untouched, and `spec:doctor` reports it too (exit `2`
  for the names, the body and `query`, the media types and a rejected keyword, exit `1` for a document fault).
  [Upgrading from 0.1](#upgrading-from-01) says what to change for each.
    - **A name clash.** A path parameter named `{request}` on an operation with a body or a `query` parameter. Two
      operations that generate one request class (`x-controller` does not rename a request). Two different schemas that
      generate one input DTO class, whether two files define a component of one name, two names reduce to one class, a
      component `UserPartial` sits beside `User`, or an inline body sits beside a component of its name. A schema name
      with no usable class name (`123`, `2fa`). Two keys of one body schema that derive one PHP property.
    - **The body and `query`.** One name in both, or a form body name PHP rewrites into a `query` name. An `allOf` whose
      branches disagree, at 3.0 and 3.1, on a request body or a `query` parameter, `additionalProperties: false` beside
      a branch that declares properties included, and at 3.1 the keywords beside a `$ref` as one more branch
      (`{$ref: User, additionalProperties: false}` over a `User` that declares properties, `{$ref: Code, type: integer}`
      over a string).
    - **Media types.** Any two different schemas under two read media types, including the same content under two names,
      or two copies that differ only in an `example` (the comparison reads every field of the normalized schema but the
      positions of ignored keywords, and [#144](https://github.com/Gcob/lara-spec-first/issues/144) would relax it). One
      schema with a file part sent as multipart by one operation and as another media type by another.
    - **References and keywords.** `$id`, `$dynamicRef`, `$dynamicAnchor`, `$recursiveRef` and `$recursiveAnchor`
      (`Rejected` now: each changes how a reference resolves, so ignoring one resolves a reference to a target the
      document never named), on any schema an operation reaches and at 3.1 beside a `$ref`. A keyword the parser hands
      back raw (`prefixItems`, `contains`, `unevaluatedItems`, `patternProperties`, `propertyNames`, `dependentSchemas`,
      `unevaluatedProperties`, `contentSchema`, `if`, `then`, `else`) that holds a `$ref`, the same keyword holding
      inline schemas being still ignored. A `$ref` aimed into a `$defs`, anywhere in the document (the parser does not
      model that keyword, so the target was a plain array it could not build a schema from). At 3.1, a `$ref` written
      inside the keywords beside a `$ref` whose target is missing or whose chain comes back through other files, a
      target or keyword the parser cannot build (`{$ref: Code, items: "x"}`), and a pure reference cycle through them.
      An `allOf` written as a mapping (`allOf: {$ref: X}`), at 3.0 too. An `x-max-depth` that is not a whole number of
      zero or more.

    These contracts built on `0.1.0` because it read no schema, not because they were served wrong, and the
    [upgrade notes](#upgrading-from-01) name the change for each.

- **Breaking: Laravel 12.22 is the new floor.** The requirement on `illuminate/routing` and `illuminate/support` rises
  from `^12.0 || ^13.0` to `^12.22 || ^13.0`: `integer:strict` is silently ignored before Laravel 12.22
  (`numeric:strict` and `boolean:strict` exist from 12.21), so on 12.0 to 12.21 an integer would accept `"42"` and
  nothing would say so. An application on an older Laravel 12 updates Laravel first.
- **Breaking: `routeAction` takes the generated request as its first parameter**, on the generated parent and on every
  child that overrides it, for an operation that states anything about its input. A custom controller written on `0.1.0`
  stops loading: PHP's fatal ("Declaration of ... must be compatible with ...") shows when the class loads, on the first
  request to that route, in a test that hits it, or in static analysis. `spec:make` writes the new signature for a new
  child only and never overwrites a file, so an existing child is edited by hand, and `spec:build` does not warn
  ([#112](https://github.com/Gcob/lara-spec-first/issues/112)). Both sides read the signature from one place, so a
  scaffold cannot disagree with the parent it extends.
- **Breaking: `spec:doctor` exits `2` on a request schema that writes an `Ignored` keyword**: `default`, `deprecated`,
  `oneOf`, `anyOf`, `not`, `discriminator`, `minProperties`, `prefixItems` and the rest of the
  [matrix's `Ignored` schema rows](./docs/guide/openapi-support.md#schemas). `default` and `deprecated` alone appear in
  most published contracts, so **a first run that was green may now be red**. The body and the `query` parameters are
  read and a response is not. At 3.1 the same keywords written beside a `$ref` are reported, at the position they are
  written. An `x-max-depth` beside a `$ref` is never honored, since a recursion reads the depth of the schema it points
  back at, so the message says to write it on the recursive schema itself. Support findings carries one finding per
  keyword and per operation, listing every position the keyword is written at, and it is a package limit: `2`, never
  `1`, and in `--json` with its `pointer`. A `Partial` keyword is named the same way for the fields it is left
  unenforced on (a `format` no Laravel rule means the same as, a `pattern` across the ECMA-262 / PCRE boundary), so a
  `format: uri` or a `format: float` in a request gates too. `format: int32` on an integer is enforced as its two
  bounds, and `int64`, `double` and `password` are handled, so none of them gates. A field the rule set drops whole (a
  `query` name PHP rewrites, a body key with `*`) names what it states the same way, so a `required` or a bound on such
  a field gates too. The exit code of a contract that writes none of them is unchanged.
- **Breaking: a `header` or `cookie` parameter makes `spec:doctor` exit `2`.** They moved from a shared `Deferred`
  count, which never gated, to an `Ignored` one, in the doctor and in the
  [support matrix](./docs/guide/openapi-support.md#parameters-bodies-responses): the package will not validate a header
  or a cookie parameter, so the row states a position rather than a gap, and a contract that declares one exits non-zero
  until the consumer acknowledges it. `query` and `requestBody` moved from `Deferred` to `Partial`, and the doctor no
  longer counts either. Every move on the exit-code axis is major in either direction, which is why a docs change is
  recorded here.
- **Breaking: `spec:doctor` can exit `2` for `encoding`, and `1` for a malformed one.** It names `encoding` as not
  enforced, and exits `2`, for a `contentType` on a part that is not read as a file (unless it is the default OpenAPI
  gives that part) and for a text type on a file part, so **a contract that writes one and was green may now be red
  until it is acknowledged**. A malformed `encoding` (a `contentType` that is not a string, an entry for a part the body
  does not declare) is a document fault and exits `1`.
- **Run `spec:build` after updating.** The new request classes, input DTOs, `routeAction` signature and `@see` in
  backticks make `spec:doctor` report `Drift` (exit `1`) until the generated tree is rebuilt.

### Fixed

- **`generated.namespace` said `STARTED` in the published config, and it is `DONE`.** It has been read since the first
  generated controller; the block said otherwise.
- **A fully-qualified name in a generated docblock's `@see` is written in backticks.** Pint's
  `fully_qualified_strict_types` rewrote an unquoted one into an import plus a short name, which means the build and a
  consumer's formatter rewrote each other forever. For a generated controller, it was an import that carried the child's
  name into a parent that shares it by design, which stopped the file loading.

### Known limits

Not a Keep a Changelog section, as in `0.1.0`: a release that ships a gap on purpose owes it a line, and each one has a
card that closes it.

- **An open object or a map body is neither validated nor carried.** A body that is `type: object` with no `properties`
  (or only `additionalProperties`) gets no rule, and its input DTO is empty: the controller reads the raw body.
  [#109](https://github.com/Gcob/lara-spec-first/issues/109).
- **A `query` name such as `page[size]` is reported, not validated.** PHP delivers it as a nested array, and no rule key
  can find it. [#110](https://github.com/Gcob/lara-spec-first/issues/110).
- **A nullable body that requires a property answers `422` to `null`**, which the contract accepts.
  [#111](https://github.com/Gcob/lara-spec-first/issues/111).
- **`spec:make` writes a child controller before the build's refusals run.** An operation the build refuses leaves a
  child that does not compile, the package never overwrites it, and an existing child whose operation gains a request
  breaks only at runtime. [#112](https://github.com/Gcob/lara-spec-first/issues/112).
- **A form body that declares a bracketed name inside another refuses a valid request**, with no finding: `filter[a]`
  beside `filter[a][b]`. A few other findings of a generated request are wider or narrower than the gap they name, for
  an empty body and for bracketed form names. [#113](https://github.com/Gcob/lara-spec-first/issues/113).
- **`spec:doctor` is silent on some constraints the generated file only mentions in prose**: a form field PHP delivers
  under another field's key, a `dependentRequired` entry the rule set drops, a `query` parameter typed array or object,
  and a few more. [#119](https://github.com/Gcob/lara-spec-first/issues/119).
- **Several recursive schemas in one operation can lose more depth than the 1,000-key ceiling needs.** The deepest is
  lowered first, which can cut a cheap schema that would have fit.
  [#124](https://github.com/Gcob/lara-spec-first/issues/124).
- **A named array inside a recursive cycle is typed by the order of the operations.** The input DTO says `mixed` or a
  `list` depending on which operation the document writes first.
  [#127](https://github.com/Gcob/lara-spec-first/issues/127).
- **A multipart part's default `contentType` is read for every OpenAPI version at once.** A part's
  `application/octet-stream` on a plain string, number, integer or boolean (3.0 and 3.1), or on an array of them, is
  read as a default rather than as a constraint, so `spec:doctor` can stay green for it. A 3.0 binary part in a form or
  mixed body is not affected: its unenforced `format` already exits `2`.
  [#131](https://github.com/Gcob/lara-spec-first/issues/131).
- **A body declared as JSON and a form at once lets a JSON client's `"42"` through**, since it keeps the loose rules and
  the generated file says so. [#133](https://github.com/Gcob/lara-spec-first/issues/133).
- **`spec:build`'s unenforced-constraint warning also counts informational findings**, such as a `readOnly` property
  that is `required`, though nothing is left unenforced. [#134](https://github.com/Gcob/lara-spec-first/issues/134).
- **A plain schema whose `enum` or `example` holds a value shaped like an unresolvable `$ref`**
  (`{"$ref": "#/nowhere"}`) stops the read, although it is data.
  [#137](https://github.com/Gcob/lara-spec-first/issues/137).
- **An object `query` parameter written with `style: deepObject` is not validated.** `style` and `explode` are not read.
  [#139](https://github.com/Gcob/lara-spec-first/issues/139).
- **At 3.1, a list constraint on an outer `$ref` hop is lost below a recursion, with no finding**, and a chain of
  aliases to siblings about twelve deep costs seconds to read.
  [#140](https://github.com/Gcob/lara-spec-first/issues/140).
- **The same schema under two names, or two copies that differ only in an `example`, are refused across two media
  types**, though they validate the same way. [#144](https://github.com/Gcob/lara-spec-first/issues/144).
- **At 3.1, a string with `contentEncoding` is read as a plain string.** A multipart part holding one defaults to
  `application/octet-stream`, and the package assumes `text/plain`, so a non-default `contentType` on it goes
  unreported. [#146](https://github.com/Gcob/lara-spec-first/issues/146).
- **At 3.1, a `contentType` written beside `style`, `explode` or `allowReserved` is still enforced**, though the
  specification ignores it there: the request gets a `mimetypes` rule and refuses an upload the contract allows.
  [#147](https://github.com/Gcob/lara-spec-first/issues/147).
- **What `0.1.0` listed still holds**: `security` is reported, not enforced; four configuration blocks are inert; there
  are no response DTOs, no pagination and no rate limiting.

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

[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.2.0
[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0
