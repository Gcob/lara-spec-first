---
title: "ADR 0023: An array inside a deepObject is read the way the app's configuration says its clients write it"
audience: Users and contributors
covers: >
    The decision that an array inside a `deepObject` query parameter is checked the way `query.deep_object_arrays` says
    (`brackets`, `comma` or `any`), why `brackets` is the default, how `comma` splits a string in the generated
    request's `prepareForValidation()`, what `any` still holds back, why a required array that may be empty is accepted
    left out under `brackets`, how an item is labeled and a boolean item is read, what of ADR 0022 still holds, and the
    alternatives turned down. It supersedes ADR 0022, which held the array at its presence until this key existed.
read_before: >
    Asking why `?filter[ids]=1,2` answers 422 by default, how to make a client that writes one string pass, why a
    generated request has a `prepareForValidation()` and a `COMMA_LISTS` constant, why a required array may be left out
    of a `deepObject`, why `spec:doctor` reports drift after the key changes, or changing how an array inside a
    `deepObject` is read.
tags: [decisions, code-generation, openapi]
---

# ADR 0023: An array inside a deepObject is read the way the app's configuration says its clients write it

> **In brief**
>
> - OpenAPI does not define how an array inside a `deepObject` is written, so the app declares it in
>   `query.deep_object_arrays` of `config/lara-spec-first.php`: `brackets` (the default,
>   `?filter[ids][]=1&filter[ids][]=2`), `comma` (`?filter[ids]=1,2`) or `any`.
> - `brackets` and `comma` check the array as a list, with its items, `minItems`, `maxItems` and `uniqueItems`. `comma`
>   splits the string in the generated request's `prepareForValidation()`, before it validates, and still accepts a list
>   written with brackets. `any` checks only that the array is there, with a finding, as
>   [ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md) did.
> - `spec:build`, `spec:make` and `spec:doctor` read the key, and any other value stops all three with a message that
>   names it. Under `brackets`, a required array that may be empty is accepted left out, since an empty list has no
>   bracketed form.
> - Turned down: reading the convention from the document, splitting in `validationData()` or with `merge()`, refusing
>   brackets under `comma`, and trimming each item.
> - This supersedes [ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md), which held the array until the key
>   existed. What it decided about the object still holds and is restated below. The default refuses a request that
>   `0.2.0` let through, as the CHANGELOG says.

**Status:** Accepted · **Cards:** [#139](https://github.com/Gcob/lara-spec-first/issues/139),
[#218](https://github.com/Gcob/lara-spec-first/issues/218)

## Context

[ADR 0022](./0022-read-a-deep-object-query-by-its-brackets.md) validates an object `query` parameter written with
`style: deepObject` through the path of a form body's nested object, and checks an array inside it at its presence only,
with a finding. It held the array on purpose: OpenAPI 3.1.1 says under `deepObject`, "The representation of array or
object properties is not defined", and 3.2.0 adds "(but see Extending Support for Querystring Formats for
alternatives)". Neither says how a value nested inside a `deepObject` is written by default.

Clients write an array three ways, and a rule about the shape of one refuses the others:

- `?filter[ids][]=1&filter[ids][]=2`, which Rails writes;
- `?filter[ids][0]=1&filter[ids][1]=2`, which PHP's `http_build_query()` writes;
- `?filter[ids]=1,2`, one string.

PHP parses the first two into the array the schema describes (`filter => [ids => ['1', '2']]`), and the third into a
string. `http_build_query()` writes nothing at all for an empty list, and `?filter[ids][]=` is one empty item, not an
empty list. Holding the array left every constraint of the contract on it unchecked, so the hold needed an end: a way
for the app to say how its clients write the array.

## Decision

**The app declares how its clients write an array, once.** `config/lara-spec-first.php` gets a block:

```php
'query' => [
    'deep_object_arrays' => 'brackets',
],
```

A missing or `null` value is `brackets`. Any other value is refused with a message that names the key and lists the
three (`UnusableSettingException`), because a typo would otherwise quietly mean `brackets`. The key is per app, as
clients are: nothing in the contract says it, and nothing asked for a reading per operation.

**One options object carries it, read once per command.** `RuleSetOptions` (internal) holds the reading, `spec:build`,
`spec:make` and `spec:doctor` read it from the configuration before they build anything, and every rule set gets it, so
an operation whose recursion passes the ceiling keeps the configured reading. An unusable value stops all three with
exit code `1`, and the doctor prints nothing else then: what it would say about the contract describes a reading the
build refuses to make. The doctor reads the key because Drift compares the generated tree with what a build would write:
a doctor on the default would call every `comma` or `any` request drifted right after a build, and with the key read,
changing it without building again is exactly what Drift reports.

**`brackets` is the default.** It is what a client that writes brackets sends, which PHP's `http_build_query()` and
Rails both do by default, and it is the one form PHP parses into the shape the schema describes, so the rules need
nothing between the query string and the validator. The array gets `array`, `list`, `min` and `max`, its items are
walked under `<key>.*`, and `uniqueItems` goes through the same rule as anywhere else:

```php
'filter.ids' => ['sometimes', 'array', 'list', 'min:1', 'max:3'],
'filter.ids.*' => ['integer', 'distinct'],
```

- **A plain string where the list belongs is refused**: `?filter[ids]=1` and `?filter[ids]=1,2` answer `422` on
  `filter.ids`, and so do indexes with a gap, since `list` is what a client that writes brackets sends.
- **Nothing is said about how the array is written.** The rules say `array` and `list`, and the guide says what a plain
  string gets, so the generated file carries no finding for the convention.
- **An item is labeled the way a client writes it**: `filter[ids][]`, with the bracket inside the backticks, in every
  finding about an item, and `filter[rows][][a]` for a property of an item. Only the query string reads so: a form
  body's item keeps `` `tags`[] ``.
- **A boolean item is text, so its finding hands over a read of the whole list.** For a plain list it gives
  `array_map(static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL), (array) $request->validated('filter.flags', []))`.
  Deeper (an item's property, a list of lists, a map of lists) it says to read each value with `filter_var()` and gives
  no code for a single key, since no one key reads them all.

**`comma` splits in the generated request's `prepareForValidation()`, into the query string's own bag.** The request
writes the method only when the contract holds an array inside a `deepObject`, and imports
`Gcob\LaraSpecFirst\Validation\CommaLists` for it:

```php
private const COMMA_LISTS = [
    ['steps' => ['filter', 'ids']],
    ['steps' => ['meta', '*'], 'spared' => [1 => ['id']]],
];

protected function prepareForValidation(): void
{
    $this->query->replace(CommaLists::split($this->query->all(), self::COMMA_LISTS));
}
```

Laravel calls the method before it reads the data to validate, so the rules, `validated()` and the request a controller
type-hints all see the list, and then the rules judge it exactly as they judge `brackets`.

- **Each list is a key, by its steps.** `*` is every key at its step, and `spared` names, by step, the keys a wildcard
  leaves alone: the names a map declares, which keep their own type, from the same list the map's `UndeclaredKeys` guard
  reads. `?meta[id]=a,b` beside a map of lists stays the string `"a,b"` when `id` is a string.
- **Every array inside the `deepObject` is a list**, at any depth and whatever its items: a property, a nested one, the
  values of a map, an array inside the objects of a list. A list of objects or of lists has no comma form, so its string
  splits into strings that its item rules refuse (`?filter[rows]=1,2` stays `422`).
- **The deepest lists are split first.** An array of arrays written as one string (`?filter[m]=1,2`) is refused, not
  split again into lists of one. Splitting the outer array first would have turned it into a valid list of lists.
- **A value is split only when it is a string, or empty.** An array is left as sent, so a client that writes brackets
  still passes under `comma`: one rule covers both, "a string where an array belongs is a comma list", never stricter
  than the convention the app declared.
- **An empty value is the empty list**: `?filter[ids]=` gives `[]`, which is how a comma list writes one, so
  `minItems: 1` refuses it and a schema without `minItems` accepts it.
- **An empty item is `null`.** `1,,2`, `1,` and `,` give an item that trims to the empty string, and Laravel skips a
  rule that is not implicit on such a value, so the item rules would accept it as no value at all, looser than the
  contract. As `null` it is judged as a bracketed empty item is after `ConvertEmptyStringsToNull`: refused, unless the
  item type allows `null`, and then `validated()` holds `null`.
- **Nothing else is trimmed.** OpenAPI writes no space after a comma, so `?filter[ids]=1,%202` is `['1', ' 2']` and the
  item rules judge the space.
- **An item cannot hold a comma.** PHP decodes `%2C` before any request sees the string, so a comma in a value and a
  comma between two values cannot be told apart. The method's docblock says it where it applies. It is the convention
  the app declared, not a gap: no counted finding.
- **`replace()` on the query bag, never `merge()`.** `merge()` writes the bag a request reads its input from, which on a
  `POST` is the body's, and the string the rules read from the query string would stay a string.
- **The app's own `request()` keeps the string as sent.** The split is in the generated request, so a controller reads
  the list from its type-hinted request or from `validated()`.
- **`CommaLists` is public API surface**, because a generated request imports it: its name and `split()` are frozen with
  the rest of what a generated file names.

**`any` is the reading ADR 0022 had, with the key in its sentence.** The array is `sometimes`, or asked for by its
parent's `required_array_keys` when required, and nothing else is written for it. The finding says it is checked only
for its presence since `query.deep_object_arrays` is `any`. The contract states the items and the bounds and none is
checked under any way of writing the array, so this is a gap and not a convention: the finding counts in the build
warning.

**Under `brackets`, a required array that may be empty is accepted left out.** An empty list has no bracketed form, so a
client with nothing to send sends no `ids`, and `required_array_keys:ids` would refuse a request the schema accepts. The
parent does not ask for the name, the generated file says so as information that loses no constraint, and a required
`filter` whose only required property is that array is accepted left out, as a `deepObject` that requires nothing a
client sends is. It applies to the query string under `brackets` and to an array with no `minItems` or a `minItems` of
`0`. With a `minItems` of `1` or more the list can be written, so the name stays required, and it does under `comma`,
where `?filter[ids]=` writes `[]`, and under `any`. A JSON body writes `[]`, and a form body keeps the rule it has.

## What of ADR 0022 still holds

ADR 0022 is superseded for its array hold only. Everything else it decided stands, and this record does not change it:

- **A `deepObject` is read whatever `explode` says**, validated through the same path as the nested object of a form
  body, under the keys PHP delivers (`?page[size]=10` under `page.size`), and **closed by its own schema** when it says
  `additionalProperties: false`.
- **A nested object reads its brackets**, with no finding: `filter[created][gte]` is validated under
  `filter.created.gte`.
- **Every finding names a property the way a client writes it** (`filter[active]`, and `user.filter[active]` for a name
  PHP rewrites), where the rule key would read `filter.active`.
- **A property whose name holds a bracket gets no rule and a finding**, since PHP delivers `a[b]` as `a[b`, and a map
  beside such a property leaves its undeclared keys unchecked.
- **A `deepObject` that shares its key with another query name keeps its fallback**: `sometimes` and a finding that
  names the other parameter.
- **A malformed `style` or `explode` is a fault in the document**, and the parameter is read as if the keyword were not
  written.
- **A required `deepObject` that requires no property a client sends is accepted left out**, since an empty object
  cannot be written in a query string. A `readOnly` property and a bracketed name do not count as sent, and now neither
  does an array that may be empty, under `brackets`.

## Alternatives turned down

- **Reading the convention from the document.** The specification does not say it, so the package would be guessing
  among three ways a client writes an array. A per-operation extension (`x-...`) on the parameter would say it in the
  document, but nothing asked for one: clients write arrays one way across an app, so the key is per app, as they are.
  An extension can come later if a user needs it.
- **Splitting in `validationData()`.** The rules would see the list, and the request a controller type-hints and
  `validated()` would disagree about it, one holding the string and one the list.
- **Writing the split with `merge()`.** It misses the query bag of a `POST`, which is the one the rules read, and the
  tests that send a `POST` fail with it.
- **Refusing brackets under `comma`.** It would make `comma` stricter than the convention the app declared for a client
  that writes either, and the request would refuse a list the schema accepts. One rule, "a string where an array belongs
  is a comma list", keeps both writings working.
- **Trimming each item.** OpenAPI writes no space after a comma, so a space is part of the value and the item rules
  judge it. Only an item that is empty becomes `null`, so Laravel does not skip its rule.

## Consequences

- **The default refuses a request `0.2.0` let through.** In `0.2.0` nothing inside a `deepObject` was checked, so
  `?filter[ids]=1,2` answered `200`. It answers `422` now under `brackets`, since the contract left the writing
  undefined and the default convention refuses a string. The CHANGELOG lists it as breaking and names the remedy: set
  `query.deep_object_arrays` to `comma` if the clients write one string, or to `any` to check only the presence. A
  generated request written before keeps its old rules until the next `spec:build`.
- **`spec:doctor` follows the key.** Under `brackets` and `comma` it reads the same rule set, so it answers the same: a
  keyword inside an item that no rule enforces (an item's `format`, `uniqueItems` on objects or under a map) moves the
  exit code from `0` to `2`, as any keyword inside a `deepObject` does. After the key changes it reports the generated
  requests as drifted until `spec:build` runs again.
- **`any` is a gap.** The build warning counts its finding. Naming the array and its keywords in `spec:doctor` is
  [#119](https://github.com/Gcob/lara-spec-first/issues/119)'s.
- **A unique list of booleans has two spellings.** `["true", "1"]` passes `distinct:strict`, the same gap a form list
  has today through the same rule, and [#197](https://github.com/Gcob/lara-spec-first/issues/197) covers a form list and
  a list inside a `deepObject` at once.
- **A top-level array `query` parameter is not read by this key.** `?tags=a,b` and a repeated name stay a finding, and
  [#229](https://github.com/Gcob/lara-spec-first/issues/229) validates them. `CommaLists` takes any path, so that card
  may reuse it, but this decision does not wire it.
- **A form body's required, possibly empty list is not changed.** It keeps its `present` rule, which refuses `[]`, and
  [#233](https://github.com/Gcob/lara-spec-first/issues/233) gives it the reading this record gives the query string.
- **An item cannot hold a comma under `comma`.** The limit comes from PHP decoding `%2C` before the request exists, and
  the guide and the generated method say it.
- **ADR 0022 keeps its wording.** Its status line points here, and the CHANGELOG entry of the object links both.
