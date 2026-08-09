---
title: Documentation Guide
audience: Contributors and agents
covers: >
    How documentation is written and organized in this repository: the
    docs-follow-code rule, the one-topic-one-file principle, the front matter
    metadata schema and tag vocabulary, and the inventory of every document.
read_before: Writing, moving, or restructuring any documentation.
tags: [ documentation, conventions, metadata, code-review, onboarding ]
---

# Documentation Guide

The rules and expectations for documentation in `lara-spec-first`. Yes, this is documentation about
documentation — and it is here for the same reason every other subject has one home: the rules apply to
contributors as much as to agents, so they should not be buried inside a file addressed to agents.

Documentation is one of the [three places](../AGENTS.md#every-change-lands-in-three-places) every change
must land, alongside the code and the tests.

## Documentation must follow the code

Documentation is part of the definition of done, not a follow-up task. This repository documents a
package that does not fully exist yet, so drift between doc and code is the expected failure mode here.
Treat a contradiction between the two as a defect, not a nitpick.

* **Ship the docs in the same change as the code.** A change to behavior, commands, config keys, or the
  public API is incomplete until the affected documents are updated alongside it.
* **In review, validate the change against what the documentation claims.** Read the relevant files from
  the [inventory](#document-inventory), then check the diff against them. Report every contradiction,
  naming the file and the line the code disagrees with. This is a required review step, not an optional
  one.
* **Work out which side is wrong.** If the code is right, the doc is stale — update it. If the doc states
  the intended design and the code departs from it (for example, making PHP authoritative over the spec),
  that is a design defect: raise it rather than quietly rewriting the doc to match the code.
* **Keep [`STACK.md`](./STACK.md) honest.** When a choice moves from `Undecided` or `Planned` to
  `Decided`, that update belongs in the same change that installs it.
* **A missing doc is a finding.** A new Artisan command, config option, or extension point that ships
  undocumented is an incomplete change.
* **American spelling.** `honor`, `behavior`, `normalize`, `organize`, `serialization`. The repository
  was swept once and is consistent; keep it that way. The exception is `composer analyse`, which is a
  command name, not prose.

## One topic, one file

Documentation follows separation of concerns, exactly like code. **Every topic has one authoritative
home, and only one.** When another document needs that topic, it links to the owning file instead of
restating it — with an anchor when it needs to point at a specific part:

```markdown
See [the code review priorities](../AGENTS.md#code-review).
Full version constraints live in [`STACK.md`](./STACK.md#supported-versions-at-a-glance).
```

The rules:

* **Duplicate the pointer, never the reasoning.** A one-line mention that orients the reader is fine —
  the README exists to sell the project and route people onward. What must never appear twice is the
  *detail*: the justification, the version numbers, the trade-off, the commands.
* **Two explanations of one subject will diverge.** Not might — will. And when they do, nothing tells a
  reader which one is stale. That is the entire cost this rule avoids.
* **A new topic that fits no existing file gets its own file** in `docs/`, plus an entry in the
  [inventory](#document-inventory). Do not append an unrelated section to whichever document happens to
  be open.
* **Prefer moving over copying.** If a section has outgrown the file it sits in, relocate it and leave a
  link behind. Never leave both copies.

Signs the rule is being broken: the same decision justified in two places; a section that re-explains
something the reader was already sent elsewhere to read; a file whose title no longer covers everything
inside it.

## Front matter metadata

The rule applies to a document's own description too. **Every Markdown file declares what it is in its
own YAML front matter** — no index elsewhere restates it. The same five fields in every file:

Four-space indentation for wrapped values and spaces inside the tag brackets, matching
`.editorconfig`. The example below is `STACK.md`'s actual front matter — if the two ever differ, this
example is the one that is wrong.

```yaml
---
title: Technical Stack
audience: Contributors and agents
covers: >
    Every technology choice with its status and reasoning, the supported PHP and
    Laravel matrix, and the rules for changing a stack decision.
read_before: Touching dependencies, version constraints, or CI configuration.
tags: [ stack, dependencies, versions, php, laravel, ci, decisions ]
---
```

| Field         | Required | Purpose                                                                                                                                |
|---------------|----------|----------------------------------------------------------------------------------------------------------------------------------------|
| `title`       | yes      | Human-readable name. May differ from the filename.                                                                                     |
| `audience`    | yes      | Who the document is written for.                                                                                                       |
| `covers`      | yes      | The subjects this file owns. If two files claim the same subject, one of them is wrong.                                                |
| `read_before` | no       | The action that should trigger reading this file. This is what makes the set navigable to an agent that has never seen the repository. |
| `tags`        | yes      | Subject keywords, for finding relevant documents without opening each one.                                                             |

Rules:

* **Front matter is authoritative.** The [inventory](#document-inventory) lists files only — it
  deliberately carries no descriptions, because that is what `covers` is for.
* **A new Markdown document without front matter is an incomplete change**, with one exception below.
* **`covers` is a claim of ownership.** Before adding a subject to a file, check that no other file
  already claims it. Overlapping `covers` fields are the earliest warning that duplication is starting.

### Tags

Tags exist so that a reader — human or agent — can find every document touching a subject with one
`grep`, without opening files or guessing at filenames. That only works if the vocabulary stays small
and consistent:

```bash
grep -rl 'tags:.*versions' --include='*.md' .
```

* **Reuse before inventing.** Check the [vocabulary](#tag-vocabulary) below first. Two tags meaning the
  same thing are worse than one imperfect tag, because each one hides half the results.
* **Lowercase, kebab-case, singular.** `code-review`, not `Code Reviews`.
* **Three to seven tags per document.** Fewer and it will not be found; more and every tag matches
  everything, which is the same as no tags at all.
* **Tag the subject, not the audience.** `audience` is already its own field.
* **Adding a new tag means adding it to the vocabulary below**, in the same change.

#### Tag vocabulary

| Tag               | Subject                                                                         |
|-------------------|---------------------------------------------------------------------------------|
| `agents`          | How AI agents should work in this repository                                    |
| `ci`              | Continuous integration and the build matrix                                     |
| `code-generation` | Producing PHP from the specification, and the rules that keep it safe to re-run |
| `code-review`     | Review priorities and how findings are reported                                 |
| `compatibility`   | What the package honors of a standard, and the promise attached to it          |
| `conventions`     | Commit, naming, and style conventions                                           |
| `contributing`    | How to contribute: setup, pull requests, conduct                                |
| `decisions`       | Choices made, with their reasoning                                              |
| `dependencies`    | Third-party packages and version constraints                                    |
| `docker`          | The containerised development environment                                       |
| `documentation`   | How documentation itself is written and organized                               |
| `laravel`         | Laravel version support and framework integration                               |
| `metadata`        | Front matter and document metadata                                              |
| `migration`       | Moving an existing Code-First app to Spec-First                                 |
| `onboarding`      | Getting a newcomer or a fresh agent productive                                  |
| `openapi`         | The OpenAPI specification and its parsing                                       |
| `php`             | PHP version support and language constraints                                    |
| `planning`        | Roadmap, phases, and sequencing                                                 |
| `scope`           | What belongs in this package and what does not                                  |
| `stack`           | Technology choices                                                              |
| `testing`         | Test levels, expectations, and the test suite                                   |
| `versions`        | Supported and required versions                                                 |
| `workflow`        | The day-to-day process of making a change                                       |

### `README.md` carries no front matter — deliberately

This is a decision, not an oversight. **Do not add front matter to `README.md`.** Two reasons:

* **The metadata exists to route readers between documents, and the README is where routing starts.**
  It is never a destination reached by consulting an index, so the fields carry no information there:
  `read_before` would read "anything else", `audience` would read "anyone", and `covers` would restate
  section headings the reader can already see.
* **It is the repository's landing page.** GitHub renders front matter as a table at the top of a
  Markdown file, which would place a metadata block above the project title on the page whose only job
  is to explain the project.

Every other Markdown document in the repository takes the full set of fields.

## Document inventory

Each document declares its own `title`, `audience`, `covers`, `read_before`, and `tags` in its
[front matter](#front-matter-metadata) — open the file, or grep the tags, to see what it owns. This list
intentionally carries no descriptions, so there is nothing here that can fall out of date.

* [`README.md`](../README.md) — no front matter, [by design](#readmemd-carries-no-front-matter--deliberately)
* [`CONTRIBUTING.md`](../CONTRIBUTING.md)
* [`AGENTS.md`](../AGENTS.md)
* [`docs/CODE-GENERATION.md`](./CODE-GENERATION.md)
* [`docs/CONTRACT-ARTIFACT.md`](./CONTRACT-ARTIFACT.md)
* [`docs/DOCTOR.md`](./DOCTOR.md)
* [`docs/DOCUMENTATION.md`](./DOCUMENTATION.md) — this file
* [`docs/LIFECYCLE.md`](./LIFECYCLE.md)
* [`docs/OPENAPI-SUPPORT.md`](./OPENAPI-SUPPORT.md)
* [`docs/REMOTE-REFERENCES.md`](./REMOTE-REFERENCES.md)
* [`docs/ROADMAP.md`](./ROADMAP.md)
* [`docs/STACK.md`](./STACK.md)
* [`LICENSE`](../LICENSE) — MIT, plain text, no front matter

Adding a Markdown document to the repository means adding it here **and** giving it front matter.
