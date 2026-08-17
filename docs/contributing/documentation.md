---
title: Documentation Guide
audience: Contributors
covers: >
    How documentation is written and organized in this repository: the docs-follow-code rule, the one-topic-one-file
    principle, the audience vocabulary and the directory each audience owns, the front matter metadata schema and tag
    vocabulary, and the inventory of every document.
read_before: Writing, moving, or restructuring any documentation.
tags: [documentation, conventions, metadata, code-review, onboarding]
---

# Documentation Guide

The rules and expectations for documentation in `lara-spec-first`. Yes, this is documentation about documentation — and
it is here for the same reason every other subject has one home: the rules apply to contributors as much as to agents,
so they should not be buried inside a file addressed to agents.

Documentation is one of the [three places](../../AGENTS.md#every-change-lands-in-three-places) every change must land,
alongside the code and the tests.

## Documentation must follow the code

Documentation is part of the definition of done, not a follow-up task. This repository documents a package that does not
fully exist yet, so drift between doc and code is the expected failure mode here. Treat a contradiction between the two
as a defect, not a nitpick.

- **Ship the docs in the same change as the code.** A change to behavior, commands, config keys, or the public API is
  incomplete until the affected documents are updated alongside it.
- **In review, validate the change against what the documentation claims.** Read the relevant files from the
  [inventory](#document-inventory), then check the diff against them. Report every contradiction, naming the file and
  the line the code disagrees with. This is a required review step, not an optional one.
- **Work out which side is wrong.** If the code is right, the doc is stale — update it. If the doc states the intended
  design and the code departs from it (for example, making PHP authoritative over the spec), that is a design defect:
  raise it rather than quietly rewriting the doc to match the code.
- **Keep [`stack.md`](../project/stack.md) honest.** When a choice moves from `Undecided` or `Planned` to `Decided`,
  that update belongs in the same change that installs it.
- **A missing doc is a finding.** A new Artisan command, config option, or extension point that ships undocumented is an
  incomplete change.
- **American spelling.** `honor`, `behavior`, `normalize`, `organize`, `serialization`. The repository was swept once
  and is consistent; keep it that way. The exception is `composer analyse`, which is a command name, not prose.

## One topic, one file

Documentation follows separation of concerns, exactly like code. **Every topic has one authoritative home, and only
one.** When another document needs that topic, it links to the owning file instead of restating it — with an anchor when
it needs to point at a specific part:

```markdown
See [the code review priorities](../../AGENTS.md#code-review). Full version constraints live in
[`stack.md`](../project/stack.md#supported-versions-at-a-glance).
```

The rules:

- **Duplicate the pointer, never the reasoning.** A one-line mention that orients the reader is fine — the README exists
  to sell the project and route people onward. What must never appear twice is the _detail_: the justification, the
  version numbers, the trade-off, the commands.
- **Two explanations of one subject will diverge.** Not might — will. And when they do, nothing tells a reader which one
  is stale. That is the entire cost this rule avoids.
- **A new topic that fits no existing file gets its own file**, in the directory
  [its audience owns](#who-the-reader-is-decides-where-the-file-lives), plus an entry in the
  [inventory](#document-inventory). Do not append an unrelated section to whichever document happens to be open.
- **Prefer moving over copying.** If a section has outgrown the file it sits in, relocate it and leave a link behind.
  Never leave both copies.

Signs the rule is being broken: the same decision justified in two places; a section that re-explains something the
reader was already sent elsewhere to read; a file whose title no longer covers everything inside it.

## Who the reader is decides where the file lives

Every document is written for one reader, and **the directory it sits in is that answer.** Placing a new document
therefore means naming its audience first. A file with two readers is not one file with a wide audience — it is two
files that have not been split yet.

This is a forcing function, not a filing convention, and it exists because the alternative was tried. Most of these
documents used to declare `Users, contributors and agents`, which is indistinguishable from declaring nobody: a page
that teaches a consumer how a build works _and_ rules a reviewer must enforce serves neither reader well, and nothing in
the repository objected. The tree objects.

| `audience`               | Lives in                                | Answers                                              |
| ------------------------ | --------------------------------------- | ---------------------------------------------------- |
| `Users`                  | `docs/guide/`                           | How to use the package, and what it promises.        |
| `Users and contributors` | `docs/project/`                         | Where the project is going, and what it is built on. |
| `Contributors`           | `docs/internals/`, `docs/contributing/` | Why it is built this way, and how to work on it.     |
| `AI coding agents`       | `AGENTS.md`                             | How an agent works in this repository.               |

The rules:

- **The vocabulary is closed.** Those four values, spelled exactly like that. Needing a fifth means needing a new
  section of the tree, which is a decision to raise rather than one to make while moving a file.
- **`agents` is never an audience alongside another.** An agent reads whatever its task touches, so naming it next to a
  human reader adds no information — that is precisely how `Users, contributors and agents` came to mean nothing.
  [`AGENTS.md`](../../AGENTS.md) is the one document whose reader _is_ an agent, and it stays at the repository root,
  where an agent looks first.
- **Two root files are conventions rather than exceptions.** [`README.md`](../../README.md) and
  [`CONTRIBUTING.md`](../../CONTRIBUTING.md) stay at the root because GitHub and every contributor expect them there.
  `CONTRIBUTING.md` is a `Contributors` document that happens to live outside `docs/contributing/`.
- **Audience decides the section, never the visibility.** Every directory above is published on the documentation site —
  see the site row in [`stack.md`](../project/stack.md). Splitting by reader is not splitting by secrecy: a contributor
  page is as worth finding, by a person or by a crawler, as a user page. What the split buys is that the reader lands
  among pages written for them.
- **Moving a document is a rename with links to fix.** Nothing else in this file changes: the topic it owns, its
  `covers` claim and its tags travel with it.

## Front matter metadata

The rule applies to a document's own description too. **Every Markdown file declares what it is in its own YAML front
matter** — no index elsewhere restates it. The same five fields in every file:

The indentation of wrapped values and the spacing inside the tag brackets belong to
[the formatter](#formatting-is-a-command-not-a-discipline), not to you. The example below is `stack.md`'s actual front
matter — if the two ever differ, this example is the one that is wrong.

```yaml
---
title: Technical Stack
audience: Users and contributors
covers: >
    Every technology choice with its status and reasoning, the supported PHP and Laravel matrix, and the rules for
    changing a stack decision.
read_before: Touching dependencies, version constraints, or CI configuration.
tags: [stack, dependencies, versions, php, laravel, ci, decisions]
---
```

| Field         | Required | Purpose                                                                                                                                 |
| ------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `title`       | yes      | Human-readable name. May differ from the filename.                                                                                      |
| `audience`    | yes      | Who the document is written for. One of [four values](#who-the-reader-is-decides-where-the-file-lives), which also fixes the directory. |
| `covers`      | yes      | The subjects this file owns. If two files claim the same subject, one of them is wrong.                                                 |
| `read_before` | no       | The action that should trigger reading this file. This is what makes the set navigable to an agent that has never seen the repository.  |
| `tags`        | yes      | Subject keywords, for finding relevant documents without opening each one.                                                              |

Rules:

- **Front matter is authoritative.** The [inventory](#document-inventory) lists files only — it deliberately carries no
  descriptions, because that is what `covers` is for.
- **A new Markdown document without front matter is an incomplete change**, with one exception below.
- **`covers` is a claim of ownership.** Before adding a subject to a file, check that no other file already claims it.
  Overlapping `covers` fields are the earliest warning that duplication is starting.

### Tags

Tags exist so that a reader — human or agent — can find every document touching a subject with one `grep`, without
opening files or guessing at filenames. That only works if the vocabulary stays small and consistent:

```bash
grep -rl 'tags:.*versions' --include='*.md' .
```

- **Reuse before inventing.** Check the [vocabulary](#tag-vocabulary) below first. Two tags meaning the same thing are
  worse than one imperfect tag, because each one hides half the results.
- **Lowercase, kebab-case, singular.** `code-review`, not `Code Reviews`.
- **Three to seven tags per document.** Fewer and it will not be found; more and every tag matches everything, which is
  the same as no tags at all.
- **Tag the subject, not the audience.** `audience` is already its own field.
- **Adding a new tag means adding it to the vocabulary below**, in the same change.

#### Tag vocabulary

| Tag               | Subject                                                                         |
| ----------------- | ------------------------------------------------------------------------------- |
| `agents`          | How AI agents should work in this repository                                    |
| `ci`              | Continuous integration and the build matrix                                     |
| `code-generation` | Producing PHP from the specification, and the rules that keep it safe to re-run |
| `code-review`     | Review priorities and how findings are reported                                 |
| `compatibility`   | What the package honors of a standard, and the promise attached to it           |
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

- **The metadata exists to route readers between documents, and the README is where routing starts.** It is never a
  destination reached by consulting an index, so the fields carry no information there: `read_before` would read
  "anything else", `audience` would read "anyone", and `covers` would restate section headings the reader can already
  see.
- **It is the repository's landing page.** GitHub renders front matter as a table at the top of a Markdown file, which
  would place a metadata block above the project title on the page whose only job is to explain the project.

Every other Markdown document in the repository takes the full set of fields.

## Formatting is a command, not a discipline

Prose in this repository is hard-wrapped to the column limit in `.editorconfig`, so that changing one word produces a
one-line diff instead of a repainted paragraph. **Nobody maintains that wrapping by hand:**

```bash
just format-md          # composer format:md, for anyone working natively
just format-md-check    # reports what needs it, writes nothing
```

- **Run it after any edit that changes line lengths** — including a rename in a path that other documents link to, which
  is what first left half of these files ragged. A reflowed paragraph is the expected diff; a paragraph carrying two
  words on its last three lines is the damage the command exists to undo.
- **The formatter is authoritative wherever it has an opinion:** wrapping, list markers, table padding, front matter
  indentation, bracket spacing. Do not hand-tune what it will rewrite, and do not raise it in review — a reviewer asking
  for a reflow is asking someone to run a command.
- **It has no opinion about content.** Every rule above this section is still yours to enforce, and no formatter can
  tell you that a topic landed in the wrong file.

The tool, the pinned version and the reason it runs outside the PHP container are a stack decision — see
[`stack.md`](../project/stack.md).

## Document inventory

Each document declares its own `title`, `audience`, `covers`, `read_before`, and `tags` in its
[front matter](#front-matter-metadata) — open the file, or grep the tags, to see what it owns. This list intentionally
carries no descriptions, so there is nothing here that can fall out of date. It is grouped by
[audience](#who-the-reader-is-decides-where-the-file-lives), because that is what the tree is grouped by.

`docs/guide/` — `Users`:

- [`code-generation.md`](../guide/code-generation.md)
- [`doctor.md`](../guide/doctor.md)
- [`lifecycle.md`](../guide/lifecycle.md)
- [`openapi-support.md`](../guide/openapi-support.md)
- [`remote-references.md`](../guide/remote-references.md)

`docs/project/` — `Users and contributors`:

- [`roadmap.md`](../project/roadmap.md)
- [`stack.md`](../project/stack.md)

`docs/internals/` — `Contributors`:

- [`contract-artifact.md`](../internals/contract-artifact.md)

`docs/contributing/` — `Contributors`:

- [`documentation.md`](./documentation.md) — this file

Repository root:

- [`README.md`](../../README.md) — no front matter, [by design](#readmemd-carries-no-front-matter--deliberately)
- [`CONTRIBUTING.md`](../../CONTRIBUTING.md) — `Contributors`
- [`AGENTS.md`](../../AGENTS.md) — `AI coding agents`
- [`LICENSE`](../../LICENSE) — MIT, plain text, no front matter

Adding a Markdown document to the repository means adding it here **and** giving it front matter.
