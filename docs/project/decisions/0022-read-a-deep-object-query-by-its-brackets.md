---
title: "ADR 0022: A deepObject query parameter is validated as the nested array PHP parses"
audience: Users and contributors
covers: >
    The decision to validate an object `query` parameter written with `style: deepObject` through the path of a form
    body's nested object, under the keys PHP delivers (`?page[size]=10` under `page.size`), whatever `explode` says; to
    read a nested object by its brackets and to check an array inside it at its presence only, until a configuration key
    says how clients write one; how every finding names a property; what a property named with a bracket, a `deepObject`
    that shares its key with another query name, and a map beside such a property get instead; what changes for a
    request and for `spec:doctor`; and the alternatives turned down. It settles what ADR 0002 left open.
read_before: >
    Asking why `?page[size]=abc` answers 422 when `page` is written with `style: deepObject`, why `explode` makes no
    difference there, why an array inside a `deepObject` is only checked for its presence, why a finding calls a
    property `filter[active]` and not `filter.active`, or changing how an object `query` parameter is read.
tags: [decisions, code-generation, openapi]
---

# ADR 0022: A deepObject query parameter is validated as the nested array PHP parses

> **In brief**
>
> - An object `query` parameter written with `style: deepObject` is validated through the same path as the nested object
>   of a form body, under the keys PHP delivers: `?page[size]=10` is judged under `page.size`. It is read whatever
>   `explode` says, and its own schema closes the object.
> - A nested object reads its brackets (`filter[created][gte]`) with no finding. An array inside it is checked at its
>   presence only, with a finding, until a configuration key (`query.deep_object_arrays`,
>   [#218](https://github.com/Gcob/lara-spec-first/issues/218)) says how clients write one. Every client writes a nested
>   object the same way and an array in several, so the package reads the first and the app declares the second.
> - A required `deepObject` that requires no property is accepted left out, since an empty object cannot be written in a
>   query string.
> - Every finding names a property the way a client writes it (`filter[active]`). A property whose name holds a bracket
>   gets no rule and a finding, a `deepObject` that shares its key with another query name keeps its fallback, and a map
>   beside a bracketed property leaves its undeclared keys unchecked.
> - Turned down: requiring `explode: true`, reading the nested values as undefined, parsing the raw query string, and
>   checking an inner array by one way of writing it.
> - This settles what [ADR 0002](./0002-judge-plain-query-names-by-shape.md) left open. It breaks requests the contract
>   refuses and moves `spec:doctor`'s exit code, as the CHANGELOG says.

**Status:** Accepted · **Cards:** [#110](https://github.com/Gcob/lara-spec-first/issues/110),
[#139](https://github.com/Gcob/lara-spec-first/issues/139) · **Pull request:**
[#228](https://github.com/Gcob/lara-spec-first/pull/228)

## Context

PHP parses the query string before Laravel sees it, so `?page[size]=10&page[number]=2` reaches the validator as
`page => [size => '10', number => '2']`. [ADR 0002](./0002-judge-plain-query-names-by-shape.md) validates a `query` name
written with brackets under that key (`page.size`) and says in its last line that it does not settle
`style: deepObject`. Until now an object `query` parameter written that way was reported and never checked: its rule was
`sometimes`, and a request with `?page[size]=abc` answered `200`.

A document writes how an object is sent with `style` and `explode`, and OpenAPI's wording leaves room:

- `deepObject` is for an object in a `query`. Its one example is `explode: true`, `color[R]=100&color[G]=200`.
- The parser the package reads documents with gives `explode: false` to a parameter that writes `style: deepObject`
  alone, which is the common way to write it, and only an `explode: true` written beside it gives `true`.
- OpenAPI 3.1.1 says, under `explode`: "Note that despite `false` being the default for `deepObject`, the combination of
  `false` with `deepObject` is undefined." OpenAPI 3.2.0 says: "For other types of parameters, or when `style` is
  `"deepObject"`, this field has no effect."
- Under `deepObject`, 3.1.1 says: "The representation of array or object properties is not defined." OpenAPI 3.2.0 says:
  "The representation of array or object properties is not defined (but see Extending Support for Querystring Formats
  for alternatives)." It points to another way to write one, and neither version says how a value nested inside a
  `deepObject` is written by default.

The package did not carry `style` and `explode` at all: `QueryParameter` held a name, a schema and `required`.

Laravel already validates the nested object of a form body the way PHP parses its brackets, so the path to give a
`deepObject` exists: `page` gets `array` and `required_array_keys`, and each property gets the rules its type gets
anywhere else, under `page.size`.

## Decision

**A `deepObject` is read whatever `explode` says.** The contract carries `style` and `explode` as the document writes
them (`QueryParameter::$style` and `$explode`, with OpenAPI's defaults for a `query`: `form` and `true`), and the
builder reads the `style` only. The reason is the sentence of 3.2.0, which settles the combination 3.1.1 calls
undefined, and the parser's own default, which would otherwise make the common way of writing the parameter the one that
is not checked. The guide and this record quote the 3.2.0 sentence.

**It goes through the same path as the nested object of a form body.** The generated rules, for a required `page` with
an integer `size` that is a multiple of 5 and a closed object, are:

```php
'page' => ['present', 'array:size,number', 'required_array_keys:size'],
'page.size' => ['sometimes', 'integer', 'min:1', new MultipleOf('5')],
'page.number' => ['sometimes', 'integer'],
```

- **Each property is read as text**, as everything in a query string is: `integer`, `numeric`, the text boolean, and the
  package's `MultipleOf` where the document writes `multipleOf`. `validated()` holds the value nested and as the text a
  query string sends.
- **The object is closed by its own schema.** A query string is open for names, which is why a plain `query` name has no
  rule on its head, but a `deepObject` has a schema that says which properties it has: `additionalProperties: false`
  gives `array:size,number`, so `?page[size]=10&page[evil]=1` answers `422` on `page`. An open object accepts any other
  key, and a map (`additionalProperties` with a schema) judges every value it does not declare.
- **A map's values are labeled `meta[*]`**, the way a client would write a key it does not know.

**A required `deepObject` that requires no property is accepted left out.** An empty object cannot be written in a query
string (`?page[size]=` is a `size` with no value, not an empty `page`), so a client with nothing to send omits the
parameter, and `present` would answer `422` to a request the schema accepts, since the schema accepts `{}`. The name
gets `sometimes` and the generated file says why, as information that loses no constraint, so the doctor does not count
it. A required `deepObject` with a required property keeps `present`: the client has that property to send. This reads
an empty object the way the package reads the empty form of a required form body.

**A nested object reads its brackets, with no finding.** `filter[created][gte]` is validated under `filter.created.gte`,
and the object `filter.created` gets `array`, or `array:<keys>` when it is closed. Every client that nests an object
writes it this way (PHP's `http_build_query`, `qs`, Rails), so reading it is a standard convention and not a guess of
the package.

**An array inside a `deepObject` is checked at its presence only, with a finding.** The key is `sometimes`, or asked for
by the parent's `required_array_keys` when the schema requires it, and nothing else is written for it: no `array`, no
bounds, no rule on its items. This holds for every array under a `deepObject`: a property, a property of a nested
object, the value of a map (written as an empty rule list, so the key stays in `validated()`), and an array of objects,
whose objects are not walked. `items`, `minItems`, `maxItems` and `uniqueItems` count as handled, so the doctor does not
name them for this array. The finding says the array is not checked beyond its presence because how a client writes one
inside a `deepObject` is not read. `?filter[ids][]=1&filter[ids][]=x`, `?filter[ids]=1,2` and `?filter[ids][0][deep]=1`
all answer `200`, and a missing required `ids` answers `422` on `filter`.

**Why an object and an array differ.** Every client writes a nested object one way, with brackets, and PHP parses it
into the shape the schema describes, so reading it is a standard convention. Clients write an array several ways
(`filter[ids][]=1`, `filter[ids]=1,2`, `filter[ids][0]=1`), and the specification calls the representation not defined.
Any rule about the array's shape or items would refuse a request the contract allows for one of them, so the package
does not pick: the app declares which way its clients write it, with `query.deep_object_arrays`
([#218](https://github.com/Gcob/lara-spec-first/issues/218)), and the hold stays until that key exists. What the
document does not settle, the app declares.

**A finding names a property the way a client writes it.** `filter[active]` and `filter[created][gte]`, and for a name
PHP rewrites, `user.filter[active]`: the contract's name for the head and brackets for the rest, where the rule key
would read `user_filter.active`. The label reaches the sentences the builder writes about a property, the information
the package writes about a required `readOnly` property or about a name that `dependentRequired` asks for without
declaring it included, and the code a text boolean's finding hands over reads the key PHP delivers it under
(`Arr::has($request->query->all(), 'filter.active')`). The dotted rule key would give
`$request->query->has('filter_active')`, which reads nothing.

**A property whose name holds a bracket gets no rule and a finding.** PHP delivers `a[b]` under the key `a[b`, so no
rule can find it, and a rule that did would answer `422` on every request. The property is left out: its value and its
presence are not enforced, even when it is required, and its finding says so in its own sentence. A closed object that
declares one gets `array` and not `array:`, since `array:` would refuse the key PHP delivers it under, and the sentence
says that undeclared keys are not refused. It records nothing in `unenforced`: reporting the properties the rule set
drops is [#119](https://github.com/Gcob/lara-spec-first/issues/119)'s, and a record here would report it twice.

**A bracketed property beside a map leaves the map's undeclared keys unchecked.** An object with a bracketed property
and an `additionalProperties` schema cannot spare that property from the guard that judges the undeclared keys as map
values, since PHP delivers it as `a[b`, a key the guard would take for an undeclared one and judge as a value. That
would be stricter than the contract, so the map's values are not validated: the finding says the key cannot be told
apart from an undeclared one, `additionalProperties` is recorded in `unenforced`, and `spec:doctor` exits `2` for it.

**A `deepObject` that shares its key with another query name keeps its fallback.** `page` written with `deepObject`
beside a `page[size]` parameter would be judged by one key, `page`, which holds the other parameter's value too. It
keeps `sometimes` and its finding, with one sentence that names the other parameter, and the other names keep their
plans. A `deepObject` with no key (`ids[]`, a head the body uses, two names on one key) keeps no rule and its existing
finding. This is [ADR 0002](./0002-judge-plain-query-names-by-shape.md)'s reasoning applied again: a build that picked
which half of the contract to enforce would be choosing for the author.

**A malformed `style` or `explode` is a fault in the document.** A `style` that is not one of the four OpenAPI defines
for a `query` (`form`, `spaceDelimited`, `pipeDelimited`, `deepObject`), and an `explode` that is not a boolean, reach
the contract as written and the builder records a document fault, the channel an `encoding` with a wrong `contentType`
uses. The parameter is then read as if the keyword were not written: a malformed `style` as no style, a malformed
`explode` as the style's default.

**What stays a finding, and what it says.** An array or object `query` parameter that is not a `deepObject` is still not
validated for its shape. Its finding now names the serialization it read (`?tag=a&tag=b`, one string, one key per
property) where it said that `style` and `explode` are not read. A required object written with `form` and
`explode: false` is sent under its own name (`?color=R,100,G,200`), so it gets the presence rule an array gets.

## Alternatives turned down

- **Requiring `explode: true`.** It follows 3.0.3's one example and reads the spec to the letter. Because the parser
  gives `false` to a document that writes `style: deepObject` alone, the most common way to write the parameter would
  stay unchecked, and 3.2.0 says the field has no effect for this style. The package would be judging a value by a
  keyword the specification retired.
- **Reading the nested values as undefined, a finding and no rule.** It never refuses a request, and it keeps what the
  package did before. It also leaves the type, bounds, `required` and closed `additionalProperties` of a parameter that
  PHP parses into exactly the shape the schema describes, and a document that states them is entitled to have them
  enforced. The contract comes first: a keyword the package can enforce faithfully is enforced.
- **Parsing the raw query string.** The package would read the names its own way, and Laravel's validator reads them
  PHP's way (`parse_str()`): the two disagree on `a.b`, `a b` and `a[b`. The query bag is what a controller and
  `validated()` hold, so the rules are keyed by what it holds.
- **Reading an inner array by one way of writing it.** Checking each item as if it were sent in brackets
  (`filter[ids][]=1`) refuses `filter[ids]=1,2`, a request the contract allows. Any reading of it is a guess among
  several ways a client writes it, and an app that picked another way would meet a refusal that no keyword of its
  contract explains. The key of [#218](https://github.com/Gcob/lara-spec-first/issues/218) lets the app say.
- **Holding a nested object as well.** It would keep one rule for both, and leave `filter[created][gte]` unchecked where
  nothing in how clients write it is ambiguous.
- **Refusing a `deepObject` that shares its key at build time.** The refusal of a name declared twice exists so the
  build does not choose which half of the contract to enforce, and no rule here makes that choice. A refusal would also
  stop a document that builds today.

## Consequences

- **A request the contract refuses is now refused.** A request that answered `200` and now answers `422` sent a value of
  the wrong type or out of bounds, an undeclared key to a closed object, or left out a required parameter or property.
  The CHANGELOG lists it as breaking: send the value the contract describes, or loosen the contract. A generated request
  written before keeps its old rules until the next `spec:build`.
- **`spec:doctor`'s exit code moves in three directions.** From `0` to `2`: a keyword inside a `deepObject` that no rule
  enforces (a property with `format: uri-reference`) is named, where the whole object was silent. From `2` to `0`: a
  map's `additionalProperties`, which the request validates now, is no longer named. From `0` to `1`: a malformed
  `style` or `explode` is a fault in the document. The array held at its presence does not move it: nothing about it is
  recorded, and what the doctor says about the type and the keywords of an array `query` parameter is
  [#119](https://github.com/Gcob/lara-spec-first/issues/119)'s.
- **An array inside a `deepObject` is the one hole in what the document states**, and it is a finding in the generated
  file until [#218](https://github.com/Gcob/lara-spec-first/issues/218) adds its key. That card checks the items by the
  default way of writing them. A unique array of booleans inside a `deepObject` then meets
  [#197](https://github.com/Gcob/lara-spec-first/issues/197)'s `distinct:strict`, which tells the two spellings of a
  boolean apart.
- **The way a client writes an array or an object is still not read outside a `deepObject`.** `form` arrays and objects,
  and the delimited styles, are findings: validating them (splitting one string, reading a repeated name) is a follow-up
  card.
- **The nested object of a form body has the same gap for a name with a bracket.** This record does not change it: it
  limits the bracketed-name rule to the query string, and the form body keeps its silent `422`. A follow-up card is the
  place for it.
- **ADR 0002 keeps its wording.** Its last line says it does not settle `style: deepObject`, and this record is what
  settles it.
