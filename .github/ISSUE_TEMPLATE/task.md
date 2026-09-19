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

## Acceptance

<!-- One to three scenarios. Delete this section on every kind but a Feature card. -->

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
does not exist yet, so they are written here and moved onto the board: Kind and Phase at
triage, Lot when that lot is actually cut. Once a field is set it is the answer and the line
below is stale text, not a second source. Leave either blank rather than guessing.
card-management.md#the-board
-->

Lot .

Kind:

<!--
Four rules this form assumes. Each names where its reasoning lives, and what makes a card
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

-->
