---
name: Task
about: Work we give ourselves, in the shape the project board uses
title: ""
labels: ""
assignees: ""
---

## Why

<!-- Two sentences. The problem, not the solution. -->

## Ready when

<!-- Cards that have to land first, as #123. Delete this section when nothing blocks. -->

- [ ]

## Assumptions

<!--
Backlog only. What we believe without having checked it, and could check by reading the code or
the docs. Before Todo, each one becomes a decision, a link under Where to look, or disappears.
Delete this section when the card reaches Todo.
-->

-

## Discovery

<!--
Backlog only. What we know we do not know, and could only find out by research or a trial.
Before Todo, each one becomes a decision, or a Decision card this one waits on in Ready when.
Delete this section when the card reaches Todo.
-->

-

## Decisions

<!--
Settled decisions only, each with its reason. A boundary is one too: what this card does not
do, and the card that does it. A question still open does not go here, it keeps the card out
of Todo. Delete this section when the card settled nothing.
-->

-

## Where to look

<!--
Permalinks only, no prose: the lines this card changes or leans on, and the documents holding
a decision that outlives it. Delete this section when there is nothing to point at.
-->

-

## Acceptance

<!-- One to three scenarios. Delete this section on every card but a Feature. -->

```gherkin
Scenario:
  Given
  When
  Then
```

## Done when

<!-- Only what this card adds on top of the three places and `composer check`. -->

- [ ]

<!--
The lot and the kind, if you know them. This form cannot set a project field, since the item
does not exist yet, so they are written here and triage moves them onto the board: Kind there
and then, Lot when that lot is actually cut. Once a field is set it is the answer and the line
below is stale text, not a second source. Leave either blank rather than guessing.
card-management.md#the-board
-->

Lot:

Kind:

<!--
Six rules this form assumes. Each names where its reasoning lives, and what makes a card
the right size is in docs/contributing/card-management.md.

- Cite a file or a line as a permalink pinned to a commit, never as a bare path.
  card-management.md#a-card-cites-a-file-as-a-permalink
- A card means a branch, named `{type}/{card}/{context}`.
  CONTRIBUTING.md#branch-names
- A Decision card carries the `decision` label, and its marker lands in the same change.
  Labelling it alone turns CI red until the marker exists.
  CONTRIBUTING.md#deferred-work-leaves-a-marker
- A Decision card is done when the document stops saying `Open`, not when somebody made up
  their mind. card-management.md#the-template-and-what-each-kind-of-card-drops
- Nothing runs the scenarios above. `Given` is a state, `When` is one trigger, `Then` is
  something you could watch happen. What other cards have done with them, none of it binding,
  is in card-management.md#an-acceptance-scenario-is-a-sentence-nothing-runs
- Whoever starts the work checks the card against the tree first. A decision that no longer
  holds stops the work and goes back to whoever settled it, rather than being decided again.
  card-management.md#a-todo-card-has-nothing-left-open

-->
