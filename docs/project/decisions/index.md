---
title: Architecture Decision Records
audience: Users and contributors
covers: >
    What an ADR is in this repository, when a decision earns one and when it does not, the sections every one carries,
    how one is numbered and named, and how a later one replaces it.
read_before: >
    Making a decision a later reader would have to work out from the code, or looking for why the package behaves the
    way it does.
tags: [decisions, documentation, conventions]
---

# Architecture Decision Records

> **In brief**
>
> - An ADR records one lasting decision: what was chosen, why, and what was turned down.
> - One is written when a newcomer needs the decision to understand the code, the package's behavior, or the project's
>   public contributing rules.
> - It is written in the pull request that makes the decision, and the topic file it touches links it.
> - Each one is `NNNN-kebab-title.md` and carries four sections: Context, Decision, Alternatives turned down,
>   Consequences.
> - A decision is never edited into another: a new ADR replaces the old one, and the old one's status line points at it.

An architecture decision record (ADR) is a short document that explains one decision a reader of the code or a user of
the package would otherwise have to guess at. The directory exists because that reasoning was spread over topic files
and kept outside the repository, which is the story told in [ADR 0001](./0001-record-lasting-decisions.md). It sits
under `docs/project/` because its readers are both users and contributors: a user asking why the package refuses a value
needs it as much as the person changing the code.

## An ADR is for what a newcomer needs

**Write one when somebody who has only the repository would need the decision to understand the code, the package's
behavior, or the project's public contributing rules.** The decisions that qualify are the ones that outlast the card
that made them:

- a support floor, such as the PHP and Laravel versions the package runs on;
- a reading of the OpenAPI specification, where the package honors, refuses or narrows what the document says;
- a public API: a class name, a config key, a command signature, the things a user's code comes to depend on;
- a public contributing rule, such as where decisions live or what a card holds.
  [ADR 0001](./0001-record-lasting-decisions.md) is one.

**Never write one for how the maintainer's own tooling runs the work**, such as the order a maintainer takes cards in or
which automated helpers the maintainer runs. Nor does a bug fix earn one: the test that pins it already says what the
package does.

**It is written in the pull request that makes the decision**, not afterward, and the topic file it touches links it.

## What an ADR holds

An ADR opens with its title as a claim and an "In brief" that states the decision first, then a status line, then four
sections. The template below shows the shape, under the front matter every document takes:

```markdown
# ADR NNNN: The decision, as a claim

> **In brief**
>
> - What was decided, in a sentence.
> - Why it was decided.
> - What it turns down or costs.

**Status:** Accepted · **Card:** [#N](https://github.com/Gcob/lara-spec-first/issues/N) · **Pull request:**
[#N](https://github.com/Gcob/lara-spec-first/pull/N)

## Context

What forced the decision: what was true, and what a reader would have done without it.

## Decision

What was chosen, stated so a reader can check the code against it.

## Alternatives turned down

Each option that was seriously considered, and the reason it lost.

## Consequences

What is now easier, what is now harder, and what the decision does not settle.
```

The four sections are fixed on purpose, so a newcomer who has met an ADR elsewhere recognizes the format.
[`documentation.md`](../../contributing/documentation.md#an-adr-holds-the-reasoning) states how that exception sits with
its heading rules. The status is `Accepted`, or `Superseded by [ADR NNNN](./NNNN-kebab-title.md)` once a later one
replaces it.

**The rule stays in the topic file, and the reasoning moves here.** How the two share a decision is in
[`documentation.md`](../../contributing/documentation.md#an-adr-holds-the-reasoning).

## Numbering and names

- **`NNNN-kebab-title.md`**, with four digits: `0001-record-lasting-decisions.md`.
- **The next number is the highest one plus one**, taken when the pull request is opened. With two cards open at once,
  the second to merge renumbers before it merges, and fixes the links to it. A merged ADR is never renumbered, since a
  topic file may already link it.
- **The title is the decision as a claim, and the filename is its short imperative form.** "Lasting decisions are
  recorded as ADRs" is `record-lasting-decisions`.

## A later ADR replaces an earlier one

**A decision that changes gets a new ADR, and the old one stays.** Its reasoning explains code that may still carry it,
and the new ADR's Context names the one it replaces. The old file's status line becomes `Superseded by [ADR NNNN](...)`,
which is the only edit it takes besides a broken link or a typo.

## What this directory does not cover

- **How the maintainer's own tooling runs the work.** No ADR holds it, and
  [card management](../../contributing/card-management.md) holds what a contributor needs of it.
- **Decisions made before this directory existed.** They stay in the topic file that owns them until somebody writes
  their ADR. A row of [`stack.md`](../stack.md) keeps its `Status`: an ADR explains a technology choice and never
  replaces its row.
