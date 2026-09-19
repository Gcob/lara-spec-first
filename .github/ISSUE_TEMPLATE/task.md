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

<!-- Feature cards only, one to three scenarios. Delete this section on a Docs or Decision card. -->

```gherkin
Scenario:
  Given
  When
  Then
```

## Done when

<!-- Only what this card adds on top of the three places and `composer check`. -->

- [ ]

<!-- The lot this belongs to, if you know it. Left blank, it gets filled in at triage. -->

Lot .

<!--
Four rules this form assumes. Their reasoning, and what makes a card the right size,
is in docs/contributing/card-management.md:

- Cite a file or a line as a permalink pinned to a commit, never as a bare path. A card written
  today and started in six months otherwise names a line that has moved.
- A card means a branch, named `{type}/{card}/{context}`, which CONTRIBUTING.md describes.
- A Decision card carries the `decision` label, and its marker lands in the same change. Labelling it
  alone turns CI red until the marker exists.
- A Decision card is done when the document stops saying `Open`, not when somebody made up their
  mind. The marker and the card die in the same commit.
-->
