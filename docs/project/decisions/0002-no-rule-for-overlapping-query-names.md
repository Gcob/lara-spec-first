---
title: "ADR 0002: Two query names PHP delivers under one key get no rule"
audience: Users and contributors
covers: >
    The decision to give no rule to either of two `query` parameters whose names PHP delivers under one key or one below
    the other (a plain `page` beside `page[size]`), what that lets through, and the alternatives turned down.
read_before: >
    Asking why `?page=abc` passes beside a `page[size]` parameter, or why a plain query name loses its rule, or changing
    how a `query` name PHP rewrites is validated.
tags: [decisions, validation, query-parameters]
---

# ADR 0002: Two query names PHP delivers under one key get no rule

> **In brief**
>
> - When two `query` parameters arrive under one key, or one below the other (`page` and `page[size]`, `ids` and
>   `ids[]`, `user_name` and `user.name`), neither gets a rule. A finding names the pair and `spec:doctor` reports what
>   each states.
> - A rule for either would judge the other's value, so a plain name's rule refused a request the contract allows.
> - The cost is a gap: `?page=abc` passes beside `page[size]`, since `page` has no rule.

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

The form body already meets the case, and
[its answer](../../guide/code-generation/request-validation.md#one-rule-set-body-and-query) was the model: two names
that overlap get no rule, and one finding names them.

## Decision

**When two `query` names overlap, none of them gets a rule, a plain one included.** They overlap when they arrive under
the same key (`user.name` and `user_name`), or when one path is below the other (`page` and `page[size]`, `filter[a]`
and `filter[a][b]`). The paths are compared step by step, never as strings, so `filter[a]` does not overlap
`filter[ab][c]`. A name PHP appends to counts as the path above its appended step, since the value can land at any index
there.

A finding names each overlapping pair and the keys they share. Each name still goes through the unenforced record, so
`spec:doctor` reports what it states (`required`, a bound) and can exit `2`. A name beside the pair is untouched, and a
plain name beside a key of the request body is still
[refused](../../guide/code-generation/request-validation.md#a-name-declared-twice-is-refused) before it is given no
rule.

## Alternatives turned down

- **Keep the plain name's rule, with a finding.** It is the behavior before this decision, made honest. It was turned
  down because the rule judges the array PHP built for the other name, so it refuses `?page[size]=3`, a request the
  contract allows. A generated request refuses only what the contract refuses, and a finding is accepted for a gap only
  when matching the contract is far too complicated. Giving the plain name no rule is simpler than keeping it.
- **Refuse the contract at build time**, as a name declared twice by the body and a `query` parameter is refused. It was
  turned down because that refusal exists to stop the build from choosing which half of the contract to enforce, and
  here no rule is emitted for either name, so there is no choice to make. Refusing would also stop a contract that
  builds today.
- **Write a rule that reads the shape of the value.** A scalar is `page`'s, an array is `page[size]`'s, and such a rule
  could judge each, so `?page=abc` would still be refused. It was turned down for its cost: it is a custom rule, with
  its own messages and its own tests, for a contract this rare. It is not impossible, and it stays an open question for
  a follow-up.

## Consequences

- **A request the contract allows is no longer refused.** `?page[size]=3` and `?ids[]=1` pass beside their plain
  neighbors.
- **A request the contract refuses can pass.** `?page=abc` beside `page[size]` is accepted, because `page` has no rule.
  The finding in the generated file says so, the guide says so, and the CHANGELOG records it.
- **`spec:doctor` can exit `2` where it exited `0`.** The plain name's keywords are now unenforced, and the doctor
  reports them.
- **Whether a rule that reads the shape of the value should replace this is open.** If it is built, a later record
  replaces this one.
- **This does not settle `style: deepObject`.** An object parameter written that way is still reported, not validated.
