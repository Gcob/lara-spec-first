---
title: Documentation Guide
audience: Contributors
covers: >
    How documentation is written and organized in this repository: the docs-follow-code rule, the one-topic-one-file
    principle, the audience vocabulary and the directory each audience owns, the front matter metadata schema and tag
    vocabulary, the TL;DR every document opens with and how it differs from a `covers` claim, the catalogue of doc
    smells and the correction each one calls for, and the inventory of every document.
read_before: Writing, moving, or restructuring any documentation.
tags: [documentation, conventions, metadata, code-review, onboarding]
---

# Documentation Guide

> **TL;DR**
>
> - Documentation is one of the three places every change lands, and a document that contradicts the code is a defect.
> - Every topic has exactly one owning file. Other files link to it rather than restate it.
> - The audience decides the directory, and every file declares itself in its own front matter.
> - Every file opens with a TL;DR, and the doc smells below are how a review names what is wrong with one.

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

| `audience`               | Lives in             | Answers                                              |
| ------------------------ | -------------------- | ---------------------------------------------------- |
| `Users`                  | `docs/guide/`        | How to use the package, and what it promises.        |
| `Users and contributors` | `docs/project/`      | Where the project is going, and what it is built on. |
| `Contributors`           | `docs/contributing/` | Why it is built this way, and how to work on it.     |
| `AI coding agents`       | `AGENTS.md`          | How an agent works in this repository.               |

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

Indentation and bracket spacing come from [the formatter](#formatting). The example below is `stack.md`'s actual front
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

| Tag               | Subject                                                                            |
| ----------------- | ---------------------------------------------------------------------------------- |
| `agents`          | How AI agents should work in this repository                                       |
| `ci`              | Continuous integration and the build matrix                                        |
| `code-generation` | Producing PHP from the specification, and the rules that keep it safe to re-run    |
| `code-review`     | Review priorities and how findings are reported                                    |
| `compatibility`   | What the package honors of a standard, and the promise attached to it              |
| `conventions`     | Commit, naming, and style conventions                                              |
| `contributing`    | How to contribute: setup, pull requests, conduct                                   |
| `decisions`       | Choices made, with their reasoning                                                 |
| `dependencies`    | Third-party packages and version constraints                                       |
| `docker`          | The containerised development environment                                          |
| `documentation`   | How documentation itself is written and organized                                  |
| `drivers`         | The driver extension mechanism and the features built on it                        |
| `laravel`         | Laravel version support and framework integration                                  |
| `metadata`        | Front matter and document metadata                                                 |
| `migration`       | Moving an existing Code-First app to Spec-First                                    |
| `onboarding`      | Getting a newcomer or a fresh agent productive                                     |
| `openapi`         | The OpenAPI specification and its parsing                                          |
| `pagination`      | Paging a collection: how a specification declares one and how the package reads it |
| `php`             | PHP version support and language constraints                                       |
| `planning`        | Roadmap, phases, and sequencing                                                    |
| `rate-limiting`   | Rate limits: how the specification declares one and how the package reads it       |
| `scope`           | What belongs in this package and what does not                                     |
| `security`        | Authentication, authorization, and the boundary of what the spec can express       |
| `stack`           | Technology choices                                                                 |
| `testing`         | Test levels, expectations, and the test suite                                      |
| `versions`        | Supported and required versions                                                    |
| `workflow`        | The day-to-day process of making a change                                          |

### The README carries no front matter, deliberately

This is a decision, not an oversight. **Do not add front matter to `README.md`.** Two reasons:

- **The metadata exists to route readers between documents, and the README is where routing starts.** It is never a
  destination reached by consulting an index, so the fields carry no information there: `read_before` would read
  "anything else", `audience` would read "anyone", and `covers` would restate section headings the reader can already
  see.
- **It is the repository's landing page.** GitHub renders front matter as a table at the top of a Markdown file, which
  would place a metadata block above the project title on the page whose only job is to explain the project.

Every other Markdown document in the repository takes the full set of fields.

## Every document opens with a summary

**Every Markdown document in the [inventory](#document-inventory) opens with a TL;DR**, directly under the `#` title and
above the first section. It carries what the reader needs if they read nothing else.

It exists because of the failure this set is most exposed to. These documents argue: they record a decision and the
reasoning that produced it, which is deliberate and is not changing. The cost is that the first thing a reader meets is
an argument, and working out what the file actually claims takes five paragraphs. The TL;DR pays that back at the top,
for four lines.

A blockquote, three to five bullets, one line each. **This file's own TL;DR is the example** — if the two ever differ,
the example is the one that is wrong:

```markdown
> **TL;DR**
>
> - Documentation is one of the three places every change lands, and a document that contradicts the code is a defect.
> - Every topic has exactly one owning file. Other files link to it rather than restate it.
> - The audience decides the directory, and every file declares itself in its own front matter.
> - Every file opens with a TL;DR, and the doc smells below are how a review names what is wrong with one.
```

The rules:

- **A blockquote, never a heading.** The site builds each page's outline from its headings, so a `## TL;DR` in every
  document adds one entry of pure noise per page. A blockquote also renders on GitHub, which is where `AGENTS.md` and
  `CONTRIBUTING.md` are actually read.
- **Three to five bullets, one line each.** Under three, and the file probably should not be a file of its own. Over
  five, and it is a table of contents rather than a summary, which is [a smell](#doc-smells) rather than a thorough
  TL;DR.
- **Assertions, not subjects.** "Nothing at runtime ever opens a specification" tells the reader something. "The
  relationship between the runtime and the specification" sends them into the body, which is what the TL;DR was there to
  spare them.
- **Name what is not built yet.** Much of this set describes behavior that does not exist, and the phase banner that
  says so is usually further down. A closing bullet separating what runs from what is designed is what stops a reader
  planning around a feature nobody has written.
- **`README.md` is exempt.** The whole file is already a summary of the project, which is the first of the two reasons
  it [carries no front matter](#the-readme-carries-no-front-matter-deliberately) either.

### A summary is not a second covers

Two summaries at the top of one file is duplication, so the split has to be stated rather than felt:

- **`covers` is written for the reader deciding whether to open the file.** It claims subjects: this is what this file
  owns. It is an index entry, and it is what makes an overlap between two files detectable.
- **The TL;DR is written for the reader who has already opened it.** It gives answers rather than subjects: this is what
  the file says about them.

The test: **a bullet that could be pasted into `covers` unchanged is a badly written bullet.** It named a topic where it
owed a claim.

## Doc smells

Everything above is a rule. This is what it looks like from the outside when one of them is being broken, named so that
a review can report a documentation problem the way it reports a code one — a finding with a correction attached, rather
than "this file feels heavy".

**A smell is a reason to look, not a verdict.** A long file that genuinely owns one subject is fine; a short one that
owns three is not. What each row gives you is the observable sign, so that the judgment happens over something you can
point at.

| Smell                              | What you see                                                                                          | What to do                                                                                                                    |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| **One file, too many subjects**    | `covers` lists subjects with nothing in common, and the title no longer describes everything under it | Split it, [one topic per file](#one-topic-one-file), and leave a link behind                                                  |
| **The decision tunnel**            | The chronology of how a decision was reached, ahead of the decision itself                            | [Result first, reason second, history never](#the-reasoning-stays-the-chronology-goes)                                        |
| **The wall of text**               | A paragraph past roughly ten lines with no bold, no bullet and no subheading anywhere in it           | An assertive heading, bold on the sentence that decides, bullets for the cases                                                |
| **Duplication**                    | The same reasoning justified in two files                                                             | Move it, never copy it, and leave a link. [The rule](#one-topic-one-file)                                                     |
| **No TL;DR**                       | Five paragraphs before the reader learns what the thing is for                                        | [The summary rule above](#every-document-opens-with-a-summary)                                                                |
| **Stale metadata**                 | A `covers` claim, a status column or a state paragraph that no longer matches the file under it       | Fix it in the change that made it stale. A `Planned` row for something already shipped is a lie the table tells on every read |
| **Undeclared future**              | Behavior that does not exist yet, written in the present tense, with no phase said out loud           | Name the phase at the top of the section, the way the guides already do                                                       |
| **A heading that asserts nothing** | "Overview", "Notes", "Details", "More on this"                                                        | Put the claim in the heading. The page outline is read as a summary, and these entries spend a line of it saying nothing      |
| **A rule with no example**         | A convention stated in prose, with nothing showing what it looks like                                 | Show the real thing, and say that it is the real thing, so that it cannot quietly go stale                                    |

### The reasoning stays, the chronology goes

The decision tunnel is the one smell that gets applied wrongly if the line is not drawn, because **this repository
documents its reasoning on purpose** and that is not what the smell complains about. `code-generation.md` carries
twenty-five `**Decision:**` blocks. They stay.

What it names is narrative, not justification:

- **Keep** the decision, the reason that holds it up, and the alternative that was rejected with why it was. A reader
  who disagrees needs all three to argue with it.
- **Cut** the sequence of events that produced it: what was tried first, what an earlier version did, what came up in
  discussion, what was built and then removed before anything shipped.

The difference is which question the reader is asking. _Why is it this way_ is answered by the reasoning. _How did we
get here_ is answered by git, which is better at it than prose and never goes stale.

## Formatting

Markdown formatting is not maintained by hand. Run it after editing documentation:

```bash
just format-md          # composer format:md, natively
just format-md-check    # reports what needs it, writes nothing
```

`.editorconfig` and `.prettierrc.json` define what it does — line width, indentation, wrapping. Read them there rather
than here. The recipes are in the `justfile`, the tool row is in [`stack.md`](../project/stack.md).

Everything above this section is about content, and no formatter checks any of it.

## The documentation site

Every document in the [inventory](#document-inventory) is published as a page of the site, including the ones at the
repository root.

```bash
just docs-install   # once
just docs           # local server, hot reload
just docs-build     # static build; fails on a dead internal link
just docs-preview   # serves the built site as it will be published
```

The same build runs on every pull request, so a dead link is a red check rather than a red deployment.

`.vitepress/config.mts` decides what is published, in what order and under which group. Page titles come from front
matter, so a document is named in one place only. Deployment is `.github/workflows/docs.yml`, and the tool row is in
[`stack.md`](../project/stack.md).

### A heading you link to carries letters, digits and spaces only

**The build catches a dead link to another file. It does not catch a dead link to an anchor on the same page**, and
those break for a reason nothing warns about: GitHub and the site turn punctuation in a heading into different anchors.
GitHub deletes the character, the site replaces it with a hyphen, so one heading yields two slugs and a hand-written
link can only satisfy one of them.

| Heading                        | On GitHub                 | On the site                |
| ------------------------------ | ------------------------- | -------------------------- |
| `### README.md and the anchor` | `readmemd-and-the-anchor` | `readme-md-and-the-anchor` |
| `### A rule — deliberately`    | `a-rule--deliberately`    | `a-rule-—-deliberately`    |

The rule that avoids the whole class: **a heading anything links to contains letters, digits and spaces, and nothing
else.** Commas are safe because both renderers drop them the same way. A period, a semicolon, a backtick or an em dash
is not, and `TL;DR` in a heading is the trap that looks most harmless.

## Document inventory

Each document declares its own `title`, `audience`, `covers`, `read_before`, and `tags` in its
[front matter](#front-matter-metadata) — open the file, or grep the tags, to see what it owns. This list intentionally
carries no descriptions, so there is nothing here that can fall out of date. It is grouped by
[audience](#who-the-reader-is-decides-where-the-file-lives), because that is what the tree is grouped by.

`docs/guide/` — `Users`:

- [`code-generation.md`](../guide/code-generation.md)
- [`controllers.md`](../guide/controllers.md)
- [`doctor.md`](../guide/doctor.md)
- [`drivers.md`](../guide/drivers.md)
- [`lifecycle.md`](../guide/lifecycle.md)
- [`openapi-support.md`](../guide/openapi-support.md)
- [`pagination.md`](../guide/pagination.md)
- [`rate-limiting.md`](../guide/rate-limiting.md)
- [`remote-references.md`](../guide/remote-references.md)
- [`security.md`](../guide/security.md)

`docs/project/` — `Users and contributors`:

- [`roadmap.md`](../project/roadmap.md)
- [`stack.md`](../project/stack.md)

`docs/contributing/` — `Contributors`:

- [`documentation.md`](./documentation.md) — this file

Repository root:

- [`README.md`](../../README.md) — no front matter, [by design](#the-readme-carries-no-front-matter-deliberately)
- [`CONTRIBUTING.md`](../../CONTRIBUTING.md) — `Contributors`
- [`AGENTS.md`](../../AGENTS.md) — `AI coding agents`
- [`LICENSE`](https://github.com/Gcob/lara-spec-first/blob/main/LICENSE) — MIT, plain text, no front matter

Adding a Markdown document to the repository means adding it here **and** giving it front matter.
