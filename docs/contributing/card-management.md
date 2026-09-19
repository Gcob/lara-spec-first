---
title: Card Management
audience: Contributors
covers: >
    How work is cut into cards and tracked: what makes a card the right size, the three tests that catch a card that is
    really a checkbox or really a lot, the failures this repository has actually made and what each one taught, the card
    template and which sections each kind of card drops, what the board's Status, Phase and Lot fields mean and who
    moves them, and the grouping mechanisms this project declines to use.
read_before: Opening a card, cutting a lot, or wondering whether something is one card or two.
tags: [planning, conventions, scope, code-review, onboarding]
---

# Card Management

> **In brief**
>
> - A card is a change that can merge into `main` on its own and leave it stable, tested and documented.
> - Size is a target, not a measurement: a pull request somebody reviews in one sitting. Under roughly four files, ask
>   whether it is a card at all; past roughly four hundred added lines, ask what else got in.
> - Three tests, in the order they catch things: does it cost more to file than to do, does its title need "and", does
>   its body carry its own open decisions.
> - The template is the [issue form](https://github.com/Gcob/lara-spec-first/blob/main/.github/ISSUE_TEMPLATE/task.md).
>   This file is where its reasoning lives.
> - `Status` moves with the work, `Phase` and `Lot` are set once at triage and rarely change.

Three lots have been cut so far, and each one needed a review to find the same two failures: a card small enough to be a
checkbox on another one, and a card large enough to be a lot. This file exists so that the next lot does not pay for
that review again.

## A card is a vertical slice

**A card is a change that can merge into `main` on its own and leave it stable, tested and documented.** That is the
whole definition, and everything below follows from it.

It is never "the emitter", then "its tests", then "its documentation". The
[three-places rule](../../AGENTS.md#every-change-lands-in-three-places) already forbids splitting those apart, so a card
that proposes it is asking for permission to ship something incomplete. When a change is genuinely too large, split it
the other way: the happy path first and the edge cases second, or one command flag at a time. Both leave each half
shippable.

## Size is a target, not a measurement

**This repository's own history cannot be the calibration, and it is worth saying why out loud.** Phase 1 was written in
very large pull requests: #12 changed 73 files and added 5,984 lines, #5 added 5,067, #16 added 4,174. Averaging that
would enshrine exactly the habit this file exists to stop. The Lot 0 cards are the opposite sample and no more usable
alone: four documentation changes, 4 to 31 files, 113 to 362 added lines. Both are ditches, not a road.

So the target is chosen rather than computed:

- **A pull request somebody reviews in one sitting, without a pause.** That is the real constraint. Everything below is
  a proxy for it.
- **Under roughly four files touched, ask whether it is a card at all.** It is usually a line in another card's
  `Done when`.
- **Past roughly four hundred added lines, ask what else got in.** Not a refusal, a question. The answer is usually a
  second card.

**No measured range is published here as a norm.** [`documentation.md`](./documentation.md) already refuses a number in
prose that an unrelated change can falsify, and a bracket computed from merged pull requests is precisely that: it moves
on its own, and it would be wrong the week somebody lands one large refactor.

## The three tests, in the order they catch things

**Does the card cost more to file than to do?** Then it is a line in another card's `Done when`.

**Does its title need "and"?** Then each verb is probably its own card.

**Does its body carry its own open decisions?** Then it is a lot, not a card. This is the one generic advice misses, and
the one this repository keeps hitting. A card whose `Done when` cannot be verified until somebody first decides
something is two pieces of work wearing one number.

## What we have actually got wrong

Named with the cards that produced them, so that the lesson is checkable rather than asserted.

**A card that says it "lands in the same change" as another one is a checkbox on that other one.**
[#37](https://github.com/Gcob/lara-spec-first/issues/37) said exactly that in its own `Ready when`, and was folded into
[#34](https://github.com/Gcob/lara-spec-first/issues/34). The three-places rule had already made it impossible for #34
to ship without it.

**A card that carries a decision, a reusable mechanism and an emitter at once is a lot.**
[#40](https://github.com/Gcob/lara-spec-first/issues/40) carries the `overrides.scan` naming and recursion decision, the
`extends`-based override discovery, and the factory emitter. Its split happens when Lot 2 opens rather than now: it
blocks nothing today, and rewriting a card before its lot is cut is the same guessing this file exists to stop.

**A card whose `Done when` names a file instead of a result cannot be verified by anybody who did not write it.** Lot 0
paid for that lesson across six cards, and the template reflects it.
`generated.namespace is read, and its config block moves from STARTED to DONE` survives a rename;
`check-open-questions.mjs exists` did not.

## The template, and what each kind of card drops

**The template lives in the
[issue form](https://github.com/Gcob/lara-spec-first/blob/main/.github/ISSUE_TEMPLATE/task.md)**, which is what GitHub
offers when somebody opens an issue. This file carries its reasoning; the form carries its shape. When the two disagree,
the form is what people actually fill in, so fix the form first and then say why here.

Four sections, of which two are usually absent:

| Section      | Carries                                                        | Dropped by                   |
| ------------ | -------------------------------------------------------------- | ---------------------------- |
| `Why`        | Two sentences: the problem, not the solution                   | Nobody                       |
| `Ready when` | Card numbers that have to land first, and nothing else         | Any card that nothing blocks |
| `Acceptance` | One to three Gherkin scenarios                                 | `Docs` and `Decision` cards  |
| `Done when`  | Only the delta on top of the three places and `composer check` | Nobody                       |

So a `Docs` card with no blocker is two sections, and only a `Feature` card routinely carries all four.

**A card carries only the delta.** Repeating the general definition of done on thirty cards is how it stops being read.
`Ready when` replaces a ceremonial definition of ready: it holds card numbers, and it is deleted outright rather than
left empty. **A `Decision` card is done when the document stops saying `Open`**, not when somebody has made up their
mind, which is the same rule [`stack.md`](../project/stack.md) applies to its own Status column.

The shape was worked out in a planning note of 15 September 2026, which is not tracked in git. Nothing here needs it:
what survived the argument is on this page, and what did not is not worth a link nobody can follow.

## The board

One project board, at [projects/1](https://github.com/users/Gcob/projects/1). Three fields carry meaning.

| Field    | Values                                                                    | Who moves it                           |
| -------- | ------------------------------------------------------------------------- | -------------------------------------- |
| `Status` | `Backlog`, `Todo`, `In Progress`, `In review`, `Ready to publish`, `Done` | Whoever is doing the work, as it moves |
| `Phase`  | `Phase 1`, `Phase 2`, `Phase 3`, `Gate 0.x`, `Gate 1.0`, `BC enforcement` | Set once when the card is filed        |
| `Lot`    | `Lot 0` through `Lot 7`                                                   | Set when the lot is cut, rarely after  |

`Status` is the only field that moves often. `Backlog` is work nobody intends to start this lot; `Todo` is the current
lot. A card with no `Lot` is a gate or an enforcement item, which belongs to no lot by design.

**The `decision` label is not decoration.** A card that settles a question a document marks `Open` carries it, and
`just check-markers --online` requires every open card carrying it to be named by at least one marker. The rule and its
consequence are in [`CONTRIBUTING.md`](../../CONTRIBUTING.md#deferred-work-leaves-a-marker).

## What this project deliberately does not use

**No epics, and no card broken into GitHub sub-issues.** `Phase` and `Lot` already group cards, and a second grouping
mechanism drifts from the first. The board's `Parent issue` and `Sub-issues progress` fields stay unused, named here
because their presence otherwise reads as an invitation.

This is not a rule about checkboxes. A markdown `- [ ]` inside `Ready when` or `Done when` is the normal way a card
lists what it owes. What is refused is promoting those lines into cards of their own.

**No estimate in hours or days.** It means nothing for a repository worked on largely by agents, and the size target
above answers the question an estimate was being asked to answer.

**No `Out of scope` section in the template.** It was considered and declined: it overlaps `Ready when` and the
[one-topic-one-file](./documentation.md#one-topic-one-file) rule, it would be filled on maybe three cards in ten, and a
fifth section nobody completes is how a template turns back into the wall of text it replaced. When a boundary genuinely
needs stating, it is a sentence in `Why`, where it reads as reasoning rather than as paperwork.

## Every link has to survive a clone

`planning/` and `reviews/` are in `.gitignore`, and `srcExclude` in `.vitepress/config.mts` drops `planning/**`,
`reviews/**`, `tests/**` and `.github/**` from the site. A relative link into any of them is a dead link in
`docs:build`, which is how `main` went red once already.

- **Tracked but unpublished**, such as the issue form under `.github/`: use an absolute `https://github.com/` URL, the
  way the [inventory](./documentation.md#document-inventory) already points at `LICENSE`.
- **Not tracked at all**, such as a planning note: there is no link to write. Cite the public card instead, which is
  permanent and is where the work lands anyway.
