---
title: "ADR 0002: A plain query name is judged by shape beside the names PHP nests under it"
audience: Users and contributors
covers: >
    The decision to judge a plain `query` name only when its value is not an array when `query` names are declared below
    it (`page` beside `page[size]`), to give no rule to two names PHP delivers under the same key, and the alternatives
    turned down.
read_before: >
    Asking why a plain query name is wrapped in `Rule::when()`, why `user_name` beside `user.name` has no rule, or
    changing how a `query` name PHP rewrites is validated.
tags: [decisions, validation, query-parameters]
---

# ADR 0002: A plain query name is judged by shape beside the names PHP nests under it

> **In brief**
>
> - A plain `query` name beside names PHP nests under it (`page` beside `page[size]`, `ids` beside `ids[]`) keeps its
>   rules, judged only when its value is not an array. The nested names keep theirs.
> - Two names PHP delivers under the same key (`user_name` beside `user.name`) have one value between them, so neither
>   gets a rule. A finding names the pair and `spec:doctor` reports what each states.
> - Turned down: giving the plain name no rule at all, which dropped `?page=2` from `validated()` and let `?page=abc`
>   through.

**Status:** Accepted · **Card:** [#110](https://github.com/Gcob/lara-spec-first/issues/110) · **Pull request:**
[#161](https://github.com/Gcob/lara-spec-first/pull/161)

## Context

PHP parses the query string before Laravel sees it, and it does not keep every name as written: `page[size]` arrives as
`page => [size => 3]`, `user.name` as `user_name`, and `ids[]` as the next free index of a list. A rule is keyed by the
name Laravel finds the value under, so `page[size]` is validated under `page.size`.

A contract that declares `page` and `page[size]` puts two parameters under one key. `?page[size]=3` is valid, and
Laravel's query bag holds `page` as an array. A rule for the plain `page`, an integer, judges that array and answers
`422` to a request the contract allows. The generated request was then stricter than the contract and said nothing about
it. The same happens for `ids` beside `ids[]`, and for `page` beside `page[*]`, where PHP appends at any index.

Not every pair is alike. `page` and `page[size]` receive different shapes: a scalar for the first, an array for the
second, and a rule can tell them apart. `user_name` and `user.name` receive the same shape under the same key, so
nothing in the data says which parameter a value belongs to.

The form body already meets both cases, and
[its answer](../../guide/code-generation/request-validation.md#one-rule-set-body-and-query) for two names under one key
was the model.

## Decision

**A plain name beside the names PHP nests under it keeps its rules, behind Laravel's own `Rule::when()`.** The rules
apply when the value is not an array, an array stands in for the nested names, and the name stays in `QUERY_KEYS`:

```php
'page' => Rule::when(
    fn (Fluent $input): bool => ! is_array($input->get('page')),
    ['sometimes', 'integer', 'min:1'],
    ['array'],
),
'page.size' => ['sometimes', 'integer'],
```

`?page=abc` and `?page=0` answer `422` on `page`, `?page=2` passes and `validated('page')` carries it, and
`?page[size]=3` passes. The `array` in the default rules keeps `validated()` from carrying a key below `page` that no
parameter declares. The same holds for a name that is a prefix of another (`filter[a]` beside `filter[a][b]`), compared
step by step and never as strings, so `filter[a]` is no ancestor of `filter[ab][c]`. A name PHP appends to counts as the
path above its appended step. No finding is written for a name that has its rules.

**Two names that arrive under the same key get no rule.** `user.name` and `user_name`, `a.b` and `a b`, and a name at an
index of a list another appends to (`ids[1]` beside `ids[]`). A finding names each pair and the key they share. Each
name still goes through the unenforced record, so `spec:doctor` reports what it states (`required`, a bound) and can
exit `2`. A plain name beside a key of the request body is still
[refused](../../guide/code-generation/request-validation.md#a-name-declared-twice-is-refused) before any of this.

## Alternatives turned down

- **Give the plain name no rule at all.** It is the simplest reading, and it was the first one taken. It was turned down
  because a name with no rule is not in `QUERY_KEYS`, so `?page=2` stopped reaching `validated('page')`, which it did
  before a nested name was declared beside it, and `?page=abc` passed. Laravel's `Rule::when()` keeps both with one
  built-in rule, so the loss bought nothing.
- **Keep the plain name's rule as it was.** It refuses `?page[size]=3`, a request the contract allows, with no finding.
  A generated request refuses only what the contract refuses.
- **Refuse the contract at build time**, as a name declared twice by the body and a `query` parameter is refused. That
  refusal exists to stop the build from choosing which half of the contract to enforce, and no rule here makes that
  choice. Refusing would also stop a contract that builds today.
- **Write a custom rule class that reads the shape of the value.** `Rule::when()` already does it with Laravel's own
  messages, so a class of the package's own adds a mechanism and a set of messages for nothing.
- **Judge two names under the same key by some rule anyway.** Both receive a scalar under one key, so no rule can tell
  which parameter a value was sent for. A rule for one would refuse what the contract allows for the other.

## Consequences

- **A request the contract allows is no longer refused.** `?page[size]=3` and `?ids[]=1` pass beside their plain
  neighbors.
- **The shape check has one blind spot.** A required `page` the client left out is not told from the nested names it
  sent instead, since the array stands in for it: `?page[size]=3` satisfies a required `page`. The guide says so.
- **`spec:doctor` can exit `2` where it exited `0`** for a plain name that arrives under the same key as a rewritten one
  (`user_name` beside `user.name`): its keywords are unenforced now. A plain name beside nested names keeps its rules,
  and the doctor reports nothing for it.
- **The generated rules for such a name are a `Rule::when()` call**, not a list. A consumer reads one extra construct in
  `rules()`, and an extra `Fluent` import.
- **This does not settle `style: deepObject`.** An object parameter written that way is still reported, not validated.
