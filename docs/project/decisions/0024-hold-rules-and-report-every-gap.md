---
title: "ADR 0024: A generated rule holds to the contract, and spec:doctor reports every gap it counts"
audience: Users and contributors
covers: >
    The decision that a generated rule is never looser than the contract and is stricter only when matching it exactly
    would be far too complex in Laravel, that every finding a rule set writes is one of four kinds recorded once, so
    `spec:build` and `spec:doctor` read the same gaps, that a standard HTTP or form reading is information and not a
    gap, the places where the code falls short of the rule today, and the alternatives turned down. It supersedes ADR
    0006, which kept the doctor silent on the gaps it called prose only.
read_before: >
    Asking why `spec:doctor` exits `2` for a field the generated request does not check, why a boolean that accepts
    `"1"` or a `PATCH` that reads `required` as empty counts for nothing, why a generated file says it left nothing
    unenforced, which finding is a gap, stricter, information or a fault, adding a finding to the rule set, or reviewing
    a rule for being too loose or too strict.
tags: [decisions, code-generation, openapi, compatibility]
---

# ADR 0024: A generated rule holds to the contract, and spec:doctor reports every gap it counts

> **In brief**
>
> - A keyword becomes the Laravel rule that means the same thing, or a report. A generated rule is never looser than the
>   contract, and never stricter unless matching it exactly would be far too complex in Laravel, and then a finding in
>   the generated file says so. This is [ADR 0006](./0006-hold-rules-to-the-contract.md)'s rule, restated.
> - Every finding a rule set writes is one of four kinds, recorded once: a gap (with the keyword and the fields it is
>   left unenforced on), a rule stricter than the contract, information, or a fault in the document. `spec:build` counts
>   a request when its record holds a gap or a fault, and `spec:doctor` reports those same entries, so a request the
>   build counts is one the doctor reports.
> - A looser rule is two things. A true contract gap is a rule the contract sets that nothing checks: it is counted and
>   the doctor exits `2`. A standard HTTP or form reading, such as a boolean that accepts `"1"` and `"0"`, a partial
>   `PATCH` or the empty form of a required form body, is information: neither counts it. What the document does not
>   settle, the app declares.
> - Turned down: the doctor staying silent on a gap because it is prose only, counting a standard reading as a gap,
>   keeping one record for the build and another for the doctor, and a rule that lets a looser one through when a card
>   names it.
> - This supersedes [ADR 0006](./0006-hold-rules-to-the-contract.md) and reverses its consequence that the doctor stays
>   silent on those gaps. It moves `spec:doctor`'s exit code from `0` to `2` for a contract that has one, as the
>   CHANGELOG says.

**Status:** Accepted · **Card:** [#119](https://github.com/Gcob/lara-spec-first/issues/119) · **Pull request:**
[#240](https://github.com/Gcob/lara-spec-first/pull/240)

## Context

[ADR 0006](./0006-hold-rules-to-the-contract.md) set the rule: a generated rule is never looser than the contract, and
never stricter unless matching it exactly would be far too complex in Laravel. It listed where the code fell short and
said `spec:doctor` stays silent on the gaps that are prose only, so a pipeline does not fail over a remark.
[ADR 0019](./0019-judge-a-json-integer-by-its-value.md) repeated it for the scalars of a body that is JSON and a form:
its finding is counted in the build warning and never `unenforced`, so the doctor says nothing.

The line between a remark and a gap was never drawn by a rule. The builder kept its findings as sentences, and the
doctor read only the few it also recorded as data: a `format`, a `pattern`, a `contentMediaType`, an `encoding` and a
keyword nothing reads. So the build said a request read a constraint it did not enforce, and the doctor exited `0` on
the same contract. A form field PHP delivers under no name, a name with a dot or a comma that `required_array_keys`
cannot hold, a body whose root is not an object, and a media type the package does not read were all in that position. A
pipeline that gated on `spec:doctor` passed over constraints nobody checked.

Two later records handed the doctor's side to this card. [ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md)
leaves a deepObject property whose name holds a bracket out of the rule set, and says that reporting the properties a
rule set drops, and what the doctor says about an array `query` parameter, is the card's.
[ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md) counts an array held under `any` in the build
warning and leaves naming it, with its keywords, to the same card.

Naming every gap raised a second question, which the rule alone does not answer. A boolean in a query string accepts
`"1"` and `"0"`, though the contract writes `true` and `false`, and every client of a query string or a form sends them.
A `PATCH` reads `required` as empty because a partial update is what the method means. Exiting `2` on those would fail a
pipeline on what a client following HTTP does, and nothing in the contract is wrong.

[ADR 0007](./0007-keep-json-scalars-strict.md) decided that a text boolean accepts the four spellings, and ADR 0006
listed its `"1"` and `"0"` among the gaps where the request is looser. The decision stands and ADR 0007's status does
not change. What moves is the class of that reading: it is a standard reading, so it is information and no longer a gap
the build counts or the doctor reports. A field with no type whose `enum` or `const` holds numbers or strings, read from
a query string or a form, is the same kind of reading: every value there is text, so `"1"` stands for `1`.

## Decision

**A keyword becomes the Laravel rule that means the same thing, or it becomes a report.** A report is a finding in the
generated file and, for what the builder records as data, a finding of `spec:doctor`. Nothing the build reads is dropped
in silence. A keyword the build does not read, such as a 3.0 keyword written beside a `$ref`, leaves no trace
([ADR 0008](./0008-honor-keywords-beside-a-ref.md)).

**A generated rule is never looser than the contract.** It never accepts a payload the contract refuses. The rule has no
exception, and a review treats a new looser rule as a defect, with or without a card open for it.

**A generated rule is never stricter than the contract, unless matching it exactly would be far too complex in Laravel,
and then a finding says so.** A rule that is stricter in silence is a defect, and a stricter rule with its finding is a
decision somebody can read. The request is stricter, with a finding, in these cases:

- **A file part's `mimetypes`** refuses a file whose name ends in a PHP extension (`php`, `phtml`, `phar`), whatever its
  type. Laravel's rule does it as a security default that no rule turns off, and matching the contract there would mean
  overriding it from a generated class.
- **A recursive schema is cut at a depth.** A payload that goes deeper answers `422`, though the contract allows it. The
  schema's `x-max-depth` sets the depth, and the finding names it
  ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)).
- **A body with `properties` and no `type`** is read as an object, so a JSON root that is not one (the string `"Ada"` is
  valid against that schema) is refused. A root that may be an object or anything else has no field for a rule to sit
  on.
- **In a body that is JSON and a form at once, a boolean keeps `boolean`**, so a form client's `"true"` is refused. The
  finding on the field names that and what a JSON client gets through. One rule set cannot read both.

A non-nullable string also refuses `""`, because Laravel's default `ConvertEmptyStringsToNull` middleware turns it into
`null` before any rule runs. That is the framework's and not a rule of the package, so the guide states it once and no
finding repeats it per field.

**Every finding a rule set writes is exactly one of four kinds, and the record says which.** `RuleWalk` keeps its
findings in private properties and takes a new one through one of four methods, so a finding cannot be written any other
way:

- **A gap** (`unenforced()`): the request enforces less than the contract. It takes at least one entry, a keyword and
  the fields it is left unenforced on, and its type is a non-empty list, so Larastan refuses a call with none. The build
  counts it and the doctor reports it.
- **Stricter** (`stricter()`): the cases above. The build does not count it.
- **Information** (`information()`): a reading of the contract that loses no constraint, written so that a reader of the
  file knows what the request accepts. The build does not count it and the doctor does not report it.
- **A fault in the document** (`fault()`): something the document writes wrong and the walk read nothing from. The build
  counts it and the doctor reports it as a fault, exit `1`.

**The build and the doctor read that one record.** `RuleSet::leavesUnenforced()` is true when a gap or a fault was
recorded, and `spec:build` counts a request in its warning that requests read a constraint they do not enforce exactly
then. `spec:doctor` reports from the same entries: one `Partial` package limit per keyword and per operation, whose
message lists the fields, and which the doctor's exit code turns into `2`. A keyword the same operation already reports
as `Ignored` is not reported twice. The finding points at the operation and not at the field, since a schema node has no
position of its own: the field is named in the message. A test pins the agreement with a row for each kind of finding
and for each operation of the doctor's fixtures, so a site that is counted and has no entry fails it.

**The one place a method chooses by its entries is a field the rule set drops that states nothing.** A `*` name, a form
name PHP delivers nowhere, a `query` name PHP appends to, a bracketed property of an open deepObject and an optional
body that is not an object and states nothing have no rule, and the contract says nothing about them: no type, no
keyword, not required. They lose nothing, so their finding is information. As soon as one states a `type`, a keyword, a
`required` or a `dependentRequired`, it is a gap, and it records what a field the rule set keeps would, and no
`required` for a `readOnly` property or for a top-level name of a `PATCH` body, which ask for none.

**A looser rule is a true contract gap or a standard reading, and only the first is counted.** A true contract gap is a
rule the contract sets that the package cannot or does not honor. It stays counted and the doctor exits `2`. A standard
HTTP or form reading is what a client following those conventions sends, which the contract read that way allows. It is
said in the generated file as information, and counts in neither the build warning nor the doctor. The examples are:

- **A boolean read as text accepts `"1"` and `"0"`,** from a `query` parameter, a form field or an item of a boolean
  list in the query string. A text boolean is the maintainer's decision, kept because the four spellings are what those
  clients send, and an exact `Rule::in(['true', 'false'])` was not taken.
- **A `PATCH` reads the required list as empty.** A partial update is what the method means (RFC 5789).
- **A required form body accepts an empty form,** which is how a form writes `{}`, when no required key refuses it.
- **A field with no type whose only keyword is an `enum` or a `const` of numbers or strings,** read from a query string
  or a form, where every value is text. In a body that is JSON the same field is a gap, since `Rule::in()` compares as
  text and accepts `"1"` and `true` for `1`.

What the document does not settle is the app's to declare, never guessed here: `query.deep_object_arrays` says how an
array inside a deepObject is written ([ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md)), and its
default is a reading the generated file says in a line of information.

**Where the code falls short of these rules today, the gap is listed, never excused.** Each has its card or its stated
limit. The lists are what is known and are examples, not a complete list: they name no count, since this record cannot
be edited after it merges and the next gap found would make a count false. Whether one more finding is information is
decided where it is written, one site at a time, and the test above only checks that the build and the doctor agree.

The request is looser than the contract, and the doctor reports the first three:

- **An integer, number or boolean of a body that is JSON and a form** keeps a loose rule, so a JSON client's `"42"`
  passes for an integer, `"1.5"` for a number, and `"1"` or `1` for a boolean. One rule set cannot read a form's text
  and still refuse a string in JSON. A finding names the field and the doctor reports its `type`
  ([ADR 0019](./0019-judge-a-json-integer-by-its-value.md) narrowed it to what remains). A runtime branch would close it
  ([#133](https://github.com/Gcob/lara-spec-first/issues/133)).
- **A recursion through a schema with no name, or through a field that is two markers at once,** validates nothing below
  the field. `x-max-depth` does not lift it, since there is no schema to write it on, and the doctor reports the field's
  `$ref` ([ADR 0009](./0009-cut-recursion-at-a-stated-depth.md)).
- **A field the request does not read whole,** each with its finding and its entries in the doctor:
    - a field the rule set drops: a form name PHP delivers under no name or under another's key, a `query` name PHP
      appends to or delivers under no key, a body name with a `*`, a dropped `dependentRequired` trigger, and a
      deepObject property whose name holds a bracket, with the closed object around it, which stops refusing undeclared
      keys ([ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md));
    - a body or a parameter read only in part: a media type the package does not read, a body whose root is not an
      object, an optional form body that asks for names only beside another key, a `query` array or object whose
      serialization the request does not read (a top-level array is
      [#229](https://github.com/Gcob/lara-spec-first/issues/229)'s), and an array inside a deepObject under `any`, with
      the keywords it states ([ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md));
    - what no Laravel rule can hold: a required name or a closed object around a name with a dot or a comma, a field of
      more than one type or typed only `null`, a field with no type that writes an `enum` or a `const` in a body that is
      JSON, or holds a boolean or a `null`, and a keyword the package does not read on a field with no type.
- **A blank string in a project that removed `ConvertEmptyStringsToNull`** can pass a property the contract refuses.
  Laravel skips every rule that is not implicit on an empty string, and only an implicit rule refuses it. The example is
  an optional integer sent as `""`: it passes, and a DTO that reads it with `(int)` gets `0`. The guide documents this
  case, and [#157](https://github.com/Gcob/lara-spec-first/issues/157) is the card for it. Under Laravel's default
  middleware stack it does not happen.
- **`pattern`'s `.`** matches `\r` and U+2028 under PCRE, which ECMA-262's does not, so `"a\rb"` passes `^a.b$`. It is a
  stated limit of [the support matrix](../../guide/openapi-support.md#strings-and-numbers), with no finding: reporting
  every pattern that uses `.` would leave most patterns unenforced, to guard against input that rarely carries `\r` or
  U+2028.
- **`format: email` against Laravel's `email`**, which follows RFC 5322, accepts a comment such as
  `test(comment)@example.com`, which JSON Schema's `email` (RFC 5321) refuses.
  [#167](https://github.com/Gcob/lara-spec-first/issues/167) tracks it, and this ADR does not decide the fix.
- **A required plain query name beside the names PHP nests under it:** `?page[size]=3` satisfies a required `page`,
  since the array stands in for it. [ADR 0002](./0002-judge-plain-query-names-by-shape.md) states it.

The request is stricter, without the reason that would allow it: Laravel's range of `[-1000, 1000]` on `minimum`,
`maximum`, `exclusiveMinimum` and `exclusiveMaximum` refuses a number the contract accepts, and nothing states it
([#226](https://github.com/Gcob/lara-spec-first/issues/226), [ADR 0021](./0021-judge-multipleof-from-the-digits.md)).

One more is the doctor's and not a request's: `spec:doctor` does not report a `contentType` that 3.1.1 would flag on a
3.1 string until the schema carries `contentEncoding`, so the doctor is looser than the contract there and the request
is not. [ADR 0005](./0005-read-default-content-type-by-latest-patch.md) states it, and
[#146](https://github.com/Gcob/lara-spec-first/issues/146) tracks it.

## Alternatives turned down

- **Keep Laravel's own rule and add a finding**, as the first `boolean` did. A finding says what a rule does, and it
  does not make the refusal right: the client still got a `422` for a request the contract allows, and a rule that
  matched the contract exactly existed (`Rule::in`).
- **Never stricter, with no exception.** Some constraints have no exact Laravel rule at a cost worth paying. Not
  refusing a payload past a recursion's cut would cost a rule of the package's own that validates recursively. A root
  that may be an object or anything else has no field for a rule to sit on, so matching it would cost a check of the
  package's own. Laravel's refusal of a PHP-named upload cannot be turned off by a rule, so matching the contract would
  cost overriding a security default from a generated class. The exception keeps Laravel's rule and says what it costs.
- **Let a looser rule through when the docs or a card name it.** A review would then accept any new looser rule once a
  card is open for it, and the contract would stop being the bar. The gaps above are not exceptions, and each one is the
  code falling short of a rule that stays.
- **State the rule and say nothing of the gaps.** The rule would then be false of the code, and a reader who found
  `"42"` passing for an integer would take the code for the bug and the page for the truth, or the other way round.
- **Keep the doctor silent on a gap because it is prose only,** as ADR 0006 and ADR 0019 did. A pipeline that gates on
  `spec:doctor` would pass while the build says a request leaves a constraint unenforced, and nothing tells a prose-only
  gap from one the doctor should name: each new gap needed that call, made by whoever wrote the finding. The doctor says
  what the build counts, and a finding that is a remark is information, not a gap kept quiet.
- **Count a standard reading as a gap.** The doctor would exit `2` on `?notify=1`, on a `PATCH` that sends one field and
  on an empty form, which no client of HTTP or a form does wrong and no contract author can fix. Teams would learn to
  accept exit `2`, and the exit code that is meant to separate a limit of the package from an author's mistake would
  stop meaning anything. The standard reading is kept as information, said where a reader of the file will see it.
- **Keep one record for the build's count and another for the doctor.** That was the code: the builder counted sentences
  and subtracted the information and the stricter ones, and the doctor read a separate list of entries. The two drifted,
  and the build counted what the doctor never reported. A single record written through four methods cannot drift, since
  a request is counted exactly when it holds a gap or a fault.
- **A pointer to the field in each `Partial` finding.** It would need a position on every node of the normal form, which
  is a change to the parser's output and not to the rule set. The finding keeps the operation's pointer and names the
  field in its message, and a later card may point at the field.

## Consequences

- **`spec:doctor` exits `2` for a contract it passed before.** A form field PHP drops, a body in a media type the
  package does not read, a required name with a dot: each is a request the build already counted. The CHANGELOG lists it
  as breaking and names the remedy: fix the contract where the finding says how, or let the CI step accept exit `2`. A
  contract can also go from `2` to `0`: a field the request drops, reported only for a `required` on a `readOnly`
  property or on a top-level name of a `PATCH` body, no longer is.
- **A generated file keeps its "Nothing was read and left unenforced" line** when it holds only stricter findings and
  information, and a request with only a text boolean, a partial `PATCH` or an empty form is no longer in the build
  warning. A request generated before keeps its older file until the next `spec:build`.
- **A new finding picks its kind where it is written.** Writing it any other way does not compile, a gap with no entry
  is refused, and the agreement test fails when a counted finding has nothing for the doctor to report. A reviewer
  checks the kind, since a standard reading has to be told from a gap by the contract and not by how the finding reads.
- **The doctor's `Partial` finding says what a keyword's row cannot.** The support matrix may rate a keyword as honored
  in general, and the finding says that on these fields the rules leave it out or hold only part of it, which the
  generated file's findings detail.
- **The lists above are examples.** A gap found later gets a card or a stated limit where its keyword is mapped, and
  this record needs no change when one more is found.
- **Earlier records keep their wording.** [ADR 0006](./0006-hold-rules-to-the-contract.md) is superseded and its status
  line points here. [ADR 0019](./0019-judge-a-json-integer-by-its-value.md),
  [ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md) and
  [ADR 0023](./0023-read-an-array-in-a-deep-object-as-the-app-declares.md) stay as they were written, and what they say
  about the doctor is replaced by this record. ADR 0019 says the finding on a scalar of a body that is JSON and a form
  is never `unenforced`, so the doctor stays silent: the doctor now reports that field's `type`. ADR 0007's text boolean
  is still the rule, now read as information.
- **What the decision leaves open** is [#133](https://github.com/Gcob/lara-spec-first/issues/133),
  [#146](https://github.com/Gcob/lara-spec-first/issues/146),
  [#157](https://github.com/Gcob/lara-spec-first/issues/157),
  [#167](https://github.com/Gcob/lara-spec-first/issues/167), [#226](https://github.com/Gcob/lara-spec-first/issues/226)
  and [#229](https://github.com/Gcob/lara-spec-first/issues/229).
