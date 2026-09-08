---
title: Documentation Guide
audience: Contributors
covers: >
    How documentation is written and organized in this repository: the docs-follow-code rule, the one-topic-one-file
    principle and what happens when a subject outgrows one file, the audience vocabulary and the directory each audience
    owns, the front matter metadata schema and tag vocabulary, the TL;DR every document opens with and how it differs
    from a `covers` claim, the three rules that keep a document readable in one pass and the junior developer test that
    calibrates them, how a diagram is built and when one earns its place, the catalogue of doc smells and the correction
    each one calls for, and the inventory of every document.
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
> - Write for one pass: the result first, and no term the reader is assumed to already know.

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

### A subject too large for one file becomes a directory

**When one topic outgrows one file, it becomes a directory holding an `index.md` and the files it splits into.** Not
several files side by side in `docs/guide/`: that turns one subject into several sidebar entries with nothing saying
they belong together, which is a worse answer to "this file is too long" than the length was a problem.

**The index is not a table of contents.** It carries what the other files depend on, plus the part of the subject
nothing else claims, and it links onward from the body where each question arises. An index that lists its own siblings
duplicates two things that already exist: each file's `covers`, and the [inventory](#document-inventory), which
deliberately carries no descriptions for exactly that reason.

Three things follow, and none of them needs configuring:

- **The site publishes the directory as one collapsible entry** whose clickable parent is the index and whose children
  are its siblings, alphabetically. `.vitepress/config.mts` names it in a `sequence` by its directory name, like any
  other page.
- **The directory answers on its own URL**, so `/docs/guide/code-generation` keeps resolving after the split and no
  external link to the subject breaks.
- **Every link written as Markdown does have to move**, because the file did. `just docs-check-anchors` is what makes a
  missed one a red check rather than a fragment nobody notices.

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
> - Write for one pass: the result first, and no term the reader is assumed to already know.
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

## Write for one pass

**A sentence the reader has to read twice has failed**, however precise it turns out to be on the second reading. These
documents are long and they argue, so the writing owes back what the arguing costs. One test says whether it did, and
three rules do most of the work.

### The Junior Dev Test

The re-read test needs a reader to run it as, or every author passes it on their own prose. **Run it as an engineer who
knows PHP and Laravel but has never seen this topic.** That is the reader this set actually gets: a contributor on their
first task, and an agent opening one page with no memory of the others.

Three questions, in order:

- **Would they get the purpose from the TL;DR alone?** If the point only lands in the third section, the summary is a
  table of contents.
- **Would they hit a term nobody defined?** This is where you find the vocabulary you assumed, and the rule below is the
  fix.
- **Would they know a rule is a rule?** State one as a present-tense fact: "the build refuses a path parameter Laravel
  cannot match", never "we felt it was probably better to refuse". Hedging reads as an open question, and an open
  question invites the reader to settle it themselves. Behavior that does not exist yet is the one exception, and it
  says so by [naming its phase](#doc-smells) rather than by softening the verb.

Where an answer is no, the fix is structural rather than editorial: split the stacked sentence into bullets, define the
term, or move the edge case into the section that owns the limits. **Not into a collapsed block** — a toggle keeps the
page looking short while leaving the reader who needed that detail worse off than a link would, and this set already
answers "secondary detail" with [a file of its own](#one-topic-one-file).

### The result comes first, the condition second

Put what happens at the front of the sentence and the circumstances behind it. A reader who stops at the comma still
leaves with the answer.

- **Write:** "Run `spec:build` to emit the routes."
- **Not:** "When you need to emit routes for your application, you should run `spec:build`."

The same rule holds at paragraph scale: the claim goes in the first sentence, the reasoning underneath it. That is what
makes a bolded lead sentence worth scanning rather than decoration.

### A coined term is linked on its first use, never left bare

This set invents vocabulary, and it is right to: [`drift`](../guide/glossary.md#drift),
[`Deferred`](../guide/glossary.md#deferred), [the marker](../guide/glossary.md#marker),
[the source map](../guide/glossary.md#source-map), [the invariant](../guide/glossary.md#invariant),
[an acknowledgement](../guide/glossary.md#acknowledgement). Each one is precise, each one means nothing to a reader who
has not met it yet, and this paragraph is the rule below applied to itself.

**The first use of a coined term in a document is a link.** Not a definition written out again: writing one in every
document that uses the word is the same clause in ten files, which is [the duplication smell](#doc-smells) with extra
steps, and the copies are what go stale.

- **Write:** "reporting [drift](../guide/glossary.md#drift) is the doctor's job"
- **Not:** "reporting drift is the doctor's job", leaving a reader who has not met the word

**Two targets are correct, and the sentence decides.** The [glossary](../guide/glossary.md) row is the default: one
target per term, so a section that moves is one row to fix rather than ten links. Link the owning section directly when
the sentence is already reaching for it — "see [the drift check](../guide/doctor.md#what-it-checks)" is better as itself
than as a detour through a row. What is never correct is the third option, which is leaving the word bare.

**The document that owns a term does not link to the glossary for it.** It carries the definition, so pointing its own
reader at a row that points back is a loop. Its first use links the section that defines it, or is that section.

**Every term carries its own anchor** in the glossary, the term itself with any leading article dropped: `#drift`,
`#invariant`, `#two-class-seam`. So the link is guessable, and `docs:check-anchors` fails the build on one that is not.

The row is one clause and a link onward to the section that owns the concept, so the reader gets the short answer in one
hop and the whole argument in two. That section stays the authority; the row is a pointer that happens to be enough most
of the time.

**First use in the document, not in the repository.** A reader arrives on one page, never on the set.

**First use, not every use.** `drift` appears twenty-five times and `acknowledgement` thirty-three; linking each one
would put a link in most sentences of `doctor.md` and teach nobody anything after the first. A later mention in the same
document is prose.

**Only the coined sense.** Most occurrences of these words are ordinary English and must stay unlinked: `drift` is a
doctor finding, but "the table drifts fastest" is a verb; `Deferred` is a support level, but "**Deferred deliberately**"
is this set's own marker for an undecided item. Linking the ordinary sense is worse than linking nothing, because it
makes the coined one invisible.

**A term with no glossary row is a term nothing owns.** Writing the row is what tells you: if there is no section to
point at, that section is the thing to write, and the row comes after it.

### Say what the document does not cover

**Every guide names its own limits, in a section of its own.** Boundaries are what a reader plans around, and they are
the first thing to go quietly stale when a feature grows into what a document once excluded.

This is already the strongest habit in the set, and the rule only makes it expected rather than occasional: the
[support matrix](../guide/openapi-support.md) states what is parsed and not honored, and
[`code-generation/index.md`](../guide/code-generation/index.md) carries both what the build deliberately does not emit
and what was decided against. A guide with no such section is claiming it has no edges.

**The section's title is not part of the rule.** "What this document does not cover" is the plain form and several
guides use it, but
[`Past the scope check, it is a Policy's job`](../guide/security.md#past-the-scope-check-it-is-a-policys-job) and
[`Laravel constraints we do not fight`](../guide/openapi-support.md#laravel-constraints-we-do-not-fight) are the same
rule kept in the assertive voice the rest of the set writes headings in. What the rule asks for is a section a reader
can find, not a phrase to grep for.

### A diagram is built, not embedded

**Diagrams are PlantUML sources under `docs/diagrams/`, rendered to an SVG committed beside each one, and referenced
from a page as an image.** Neither GitHub nor the site renders PlantUML on its own, and half the readers of this set are
on GitHub, so a fenced `plantuml` block is a code listing to one of them and a diagram to neither.

```bash
just diagrams          # render every docs/diagrams/*.puml to the SVG beside it
just diagrams-check    # reports a diagram edited without being rebuilt, writes nothing
```

`scripts/build-diagrams.sh` runs PlantUML through Docker, the same shape as [Markdown formatting](#formatting) and for
the same reason: PlantUML is a Java tool and the development image carries no Java. A PlantUML on your `PATH` is the
fallback for a render and never the preference, because the image is pinned and your copy is not; `diagrams-check`
refuses it outright, since it compares byte for byte and the fonts a local JVM can see move the coordinates. The tool
row is in [`stack.md`](../project/stack.md).

Three rules keep a rendered diagram honest:

- **Commit the source and the SVG, and let CI compare them.** A generated file in the tree can drift from what produced
  it. `diagrams-check` re-renders into a scratch directory and fails when the committed SVG differs, which turns that
  drift into a red check rather than a diagram quietly describing an older design. It fails in the other direction too,
  on an SVG whose `.puml` was deleted: a picture nothing produces any more would otherwise stay green forever.
- **One neutral grey, on a transparent background.** The SVG is one file serving a light theme and a dark one, so it
  cannot carry a palette. `#888888` clears the contrast floor against both, and the diagram borrows the page's ground
  rather than painting its own.
- **A diagram never carries a fact alone.** It complements the prose, the table and the rules around it; anything only
  the picture says is lost to a reader using a screen reader, and to every `grep`.

#### When a diagram earns its place

**Three steps, or an ordering the prose has to spell out.** Below that a sentence wins, and a diagram of two boxes costs
a build step to say what a clause already said.

The types worth drawing, and the ones this package has no use for, stated so that nobody draws one to fill the table:

| Diagram             | Shows                                            | Here                                                                             |
| ------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------- |
| Activity            | The steps of a workflow or an algorithm          | The reading pipeline, the order of a build                                       |
| Sequence            | The order of calls between objects or services   | A request reaching a generated controller and its custom child                   |
| State machine       | An entity's lifecycle and the rules that move it | An operation across `beta`, `stable`, `deprecated` and sunset                    |
| Class               | The concepts and how they relate                 | The [two-class seam](../guide/glossary.md#two-class-seam), the `Contract\` types |
| Component           | Module and package boundaries                    | What `Parsing\` may reach, and what `Routing\` may not                           |
| Use case            | Who interacts with the system, and to do what    | Nothing yet. Three Artisan commands are a list, not a diagram                    |
| Entity relationship | The tables of a relational database              | Nothing. This package has no database                                            |
| Deployment          | The machines and containers the code runs on     | Nothing. This is a library, and the host is the consumer's                       |

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
| **The sentence you read twice**    | You reached the end of it and went back to the start                                                  | Split it, and [put the result first](#write-for-one-pass)                                                                     |
| **The stacked clause**             | More than two commas or conditions carried by one sentence                                            | One idea per sentence, or bullets when the sentence was really a list                                                         |
| **Duplication**                    | The same reasoning justified in two files                                                             | Move it, never copy it, and leave a link. [The rule](#one-topic-one-file)                                                     |
| **No TL;DR**                       | Five paragraphs before the reader learns what the thing is for                                        | [The summary rule above](#every-document-opens-with-a-summary)                                                                |
| **Stale metadata**                 | A `covers` claim, a status column or a state paragraph that no longer matches the file under it       | Fix it in the change that made it stale. A `Planned` row for something already shipped is a lie the table tells on every read |
| **Undeclared future**              | Behavior that does not exist yet, written in the present tense, with no phase said out loud           | Name the phase at the top of the section, the way the guides already do                                                       |
| **A heading that asserts nothing** | "Overview", "Notes", "Details", "More on this"                                                        | Put the claim in the heading. The page outline is read as a summary, and these entries spend a line of it saying nothing      |
| **A rule with no example**         | A convention stated in prose, with nothing showing what it looks like                                 | Show the real thing, and say that it is the real thing, so that it cannot quietly go stale                                    |

### The reasoning stays, the chronology goes

The decision tunnel is the one smell that gets applied wrongly if the line is not drawn, because **this repository
documents its reasoning on purpose** and that is not what the smell complains about. `code-generation/index.md` carries
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

**The theme is the default one, with a stylesheet layered over it.** `.vitepress/theme/custom.css` holds every visual
change this site makes, and each rule carries the reason it exists. There is one today, and it follows from how this set
writes headings: a heading here is an assertive sentence, which the default right-hand outline truncates to an ellipsis
mid-sentence. The outline wraps instead, and widens on a screen with the room for it. A rule there overrides a selector
the default theme owns, so keep them few and check them after a VitePress upgrade.

### An anchor is written the way GitHub writes it

**Write a same-page link the way GitHub would slug the heading: lowercase it, delete the punctuation, turn the spaces
into hyphens.** ``## Watching: `spec:watch` `` is `#watching-specwatch`, with the colons gone rather than turned into
hyphens.

That is one form and not two because the site is configured to slug a heading exactly as GitHub does
(`markdown.anchor.slugify` in `.vitepress/config.mts`, which calls `github-slugger` rather than reproducing it). Left at
its default, the site replaces a punctuation mark where GitHub deletes it, one heading yields two different anchors, and
a hand-written link can only ever satisfy one of them. Fourteen dead ones had accumulated that way before anyone looked,
so the override is what closed the class rather than a rule asking every author to keep two slug algorithms in their
head.

**What still gets past the build is a heading renamed while a link to it was not.** VitePress checks a link's file and
never its fragment, so nothing about that is visible to `docs:build`:

```bash
just docs-build           # first: the check reads the built site
just docs-check-anchors   # every link whose anchor no heading produces
```

It runs in CI beside the build. It reads the emitted ids rather than re-deriving them from the Markdown, so there is no
second implementation of the slug rule to disagree with the first.

## Document inventory

Each document declares its own `title`, `audience`, `covers`, `read_before`, and `tags` in its
[front matter](#front-matter-metadata) — open the file, or grep the tags, to see what it owns. This list intentionally
carries no descriptions, so there is nothing here that can fall out of date. It is grouped by
[audience](#who-the-reader-is-decides-where-the-file-lives), because that is what the tree is grouped by.

`docs/guide/` — `Users`:

- [`code-generation/index.md`](../guide/code-generation/index.md)
    - [`generated-file-anatomy.md`](../guide/code-generation/generated-file-anatomy.md)
    - [`publishing.md`](../guide/code-generation/publishing.md)
    - [`response-dtos.md`](../guide/code-generation/response-dtos.md)
    - [`scaffolding.md`](../guide/code-generation/scaffolding.md)
- [`controllers.md`](../guide/controllers.md)
- [`doctor.md`](../guide/doctor.md)
- [`drivers.md`](../guide/drivers.md)
- [`glossary.md`](../guide/glossary.md)
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
