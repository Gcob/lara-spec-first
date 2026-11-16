---
title: "ADR 0001: Lasting decisions are recorded as ADRs"
audience: Users and contributors
covers: >
    The decision to record a lasting decision as an ADR under `docs/project/decisions/`, why the topic files alone were
    not enough, and the alternatives turned down.
read_before: Asking why decisions are written down the way they are, or proposing a different home for them.
tags: [decisions, documentation, conventions]
---

# ADR 0001: Lasting decisions are recorded as ADRs

> **In brief**
>
> - A lasting decision is recorded as an ADR in `docs/project/decisions/`, in the pull request that makes it.
> - Decisions lived in topic files and in notes outside the repository, so GitHub could not explain the project alone.
> - The topic file keeps the rule and a one-line reason, and links the ADR. The ADR owns the context and the
>   alternatives.
> - The ADR directory sits under `docs/project/`, because users read these decisions too.

**Status:** Accepted · **Card:** [#152](https://github.com/Gcob/lara-spec-first/issues/152) · **Pull request:**
[#160](https://github.com/Gcob/lara-spec-first/pull/160)

## Context

A decision in this project was written down in one of two places. Either it was a heading in the topic file that owns
its subject, with its reason under it and the rejected alternative named (the rule of
[`documentation.md`](../../contributing/documentation.md#one-topic-one-file)), or it lived in notes kept outside the
repository. A reader on GitHub met the rule without the reasoning, and had to know which topic file to open to find the
part that was written. The repository could not explain the project on its own.

[`card-management.md`](../../contributing/card-management.md#an-interview-is-one-way-to-run-the-third-test) declined
ADRs on purpose, because a decision already went into the topic file that owns it. That held while the reason fit beside
the rule. It does not hold for a decision with several alternatives and a history: a support floor, a reading of the
OpenAPI specification, a public API. Those matter to users as much as to contributors, and a user never opens a
contributor file to find out why the package refuses something.

## Decision

**A decision that earns an ADR is recorded as one in `docs/project/decisions/`, in the pull request that makes it.** The
[index](./index.md#an-adr-is-for-what-a-newcomer-needs) says which decisions earn one, and holds the directory and the
sections an ADR carries. This one qualifies: where a project keeps its decisions is a public contributing rule.

**The topic file keeps the rule and its one-line reason, and links the ADR.** The ADR owns the context and the
alternatives turned down. [`documentation.md`](../../contributing/documentation.md#an-adr-holds-the-reasoning) says how
the two share a decision.

## Alternatives turned down

- **Keep decisions only in their topic files**, which is what `card-management.md` said until this decision. Nothing is
  duplicated and nothing is reversed. It was turned down because the reasoning stays spread over the topic files, and a
  decision made outside the repository stays outside it.
- **Put the ADRs in `docs/contributing/decisions/`.** The record is the same, with the audience narrowed to
  contributors. It was turned down because a user asking why the package refuses a value would never look in a
  contributor directory.

## Consequences

- **The rule and its reason now live in two files.** The link between them has to be kept. `just docs-check-anchors`
  catches an anchor that no longer resolves, `just docs-build` a link to a missing file, and review a reason that no
  longer matches its ADR.
- **An ADR departs from the heading rules of `documentation.md` on purpose**, and that guide states the exception in one
  place, so a review does not flag it again.
