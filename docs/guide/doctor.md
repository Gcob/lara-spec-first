---
title: The Doctor
audience: Users
covers: >
    The `spec:doctor` command: why diagnostics live in a command rather than in the request path, its output contract
    and flags, the two classes of finding it never mixes, everything it checks, and the acknowledgement configuration
    that lets a consumer accept a limit without silencing the package.
read_before: >
    Changing what the package reports, adding a check, or touching the acknowledgement configuration.
tags: [openapi, compatibility, decisions, workflow, code-review]
---

# The Doctor

`spec:doctor` is how this package keeps its second rule: **a construct it does not honor must produce a diagnostic.**
What is honored in the first place lives in [`openapi-support.md`](./openapi-support.md); this document owns how any of
it is reported.

> **Not implemented yet.** Phase 1 of the [Roadmap](../project/roadmap.md). Items marked `Open` are undecided.

Rule 2 has an obvious failure mode. A package that reports every unhonored construct at boot is a package that shouts on
every request, and a tool that shouts constantly gets its output filtered out — at which point the diagnostic exists and
nobody reads it, which is rule 2 defeated by its own enforcement.

**Decision: the diagnostics get their own command, `spec:doctor`. `nginx -t`, not a log line.**

The model is deliberate. `nginx` does not warn you about your configuration on every request; it gives you one command
that answers _"is this configuration good?"_, exits non-zero when it is not, and is therefore the thing you run before a
reload and the thing CI runs on every commit. That is the shape this package needs, for the same reason: a Spec-First
package's most valuable output is not "your request failed", it is **"here is exactly what your contract will and will
not do once loaded"** — and that answer is worth reading _before_ the app runs, not during.

This is not a Phase 2 developer-experience nicety. **It is the enforcement mechanism for rule 2, so it ships with the
first thing that reads a spec** — see the [Roadmap](../project/roadmap.md).

## The contract

- **One command, not two — validation is centralized.** "Is my document valid OpenAPI" and "will this package honor it"
  are genuinely different questions, and they are deliberately answered in one place. Every check that reads the spec
  shares one report format, one exit contract, and one place to add the next check. Splitting them would mean two
  commands to wire into CI, two output formats to parse, and a standing question about which one to run. Validity is the
  doctor's first section, not a separate command.
- **The exit code is the API.** Non-zero means at least one construct in the document will not be honored as written.
  Zero means none will — with one deliberate exception: [`Deferred`](./openapi-support.md#support-levels) rows report
  what the package has not built yet, and do not fail a pipeline over our roadmap. Zero is therefore _nothing here is
  being dropped without a decision behind it_, not _everything in this document is implemented_. That single property is
  what makes the command usable as a CI gate and a pre-deploy gate, and what stops the report from becoming decorative.
- **Machine-readable output.** Real specs are large, and the report grows with them. A `--json` flag lets CI annotate a
  pull request instead of dumping a wall of text, and lets tooling — including AI agents, which is a first-class use
  case for this package — consume the findings without parsing prose. Cheap to design in, awkward to retrofit.
- **Read-only, always.** It never writes a cache, never touches the database, never mutates state, so it is safe to run
  anywhere it has something to read. That caveat is real: a deployment that ships only the generated PHP has no
  specification on disk, and the doctor's contract checks have nothing to work from there. Its natural homes are
  development and CI, where the whole repository is present.
- **Report everything, not the first failure.** `nginx -t` stops at the first syntax error because a config file is a
  linear thing. A support matrix is not: a developer needs the full list of what was ignored in one pass, otherwise
  adoption becomes a whack-a-mole loop.
- **Every finding names the document position.** File, JSON pointer, and the
  [support level](./openapi-support.md#support-levels) that applies. A finding you cannot locate is a rumor.
- **It reports the outcome, not only the problems.** The resolved routing table — which routes will exist, in which
  order, mapped to which controller and method, and whether that controller exists — is the single most useful thing
  this command can print. Most runs will be clean, and a command that prints nothing on success teaches the developer
  nothing about what the spec actually did.

## Planned flags

Centralizing every check in one command means that command needs a way to narrow what it runs. The intended surface, all
provisional:

| Flag              | Purpose                                                                                |
| ----------------- | -------------------------------------------------------------------------------------- |
| `--json`          | Machine-readable findings, for CI annotation and for tooling that consumes the report. |
| `--check=syntax`  | Document validity only: is this valid OpenAPI.                                         |
| `--check=honored` | Support findings only: what this package will and will not honor.                      |

Those two values do not partition the [sections below](#what-it-checks) — drift, installation and artifact freshness
fall under neither, and inventing a value per section would turn a filter into a second command. **Open:** whether
`--check` names sections directly rather than naming two categories.

The doctor takes no flag that lets it reach the network. It has no reason to: every remote reference is already
[vendored locally](./remote-references.md#a-remote-reference-is-a-dependency-not-a-cache-entry), so a blocked or missing
reference is diagnosed by reading the working tree, and fetching belongs to the build. A `--bypass-allowlist` escape
hatch, if one is ever wanted, belongs on the fetching path, not here.

Two constraints on any flag added here, and they are the reason this list is short:

- **A filtered run's exit code covers only what it ran.** `--check=syntax` exiting zero means the document is valid, not
  that the package will honor it. The report says which checks were skipped, on every run, so a green exit is never
  mistaken for a full pass.
- **A flag that changes what the package would actually do makes the run non-representative, and the report must say
  so.** A clean run under such a flag does not predict a clean boot, so the report labels it, and CI has no business
  using it. Without that label, a flag quietly breaks the one property that makes the exit code worth anything.

## Two kinds of finding, never mixed

A single command answering both questions only works if the report never blurs them. **"Your document is broken" and
"this package cannot honor your document" are different problems, with different owners, different fixes, and different
urgency** — and a developer who cannot tell them apart at a glance will treat the whole report as noise.

| Class              | Means                                                                                                                                           | Who fixes it                                                           | How                                                                                                                        |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Document fault** | The document is not valid OpenAPI, or is internally inconsistent: schema violations, an unresolvable `$ref`, a path parameter declared nowhere. | The spec author.                                                       | Fix the document. There is no other option, and the package will not guess.                                                |
| **Package limit**  | The document is correct. This package does not honor the construct.                                                                             | Us, eventually — it is a roadmap item, not a defect in their contract. | The consumer changes the spec, waits for support, or [acknowledges the limit](#acknowledged-limits-the-consumers-opt-out). |

The distinction has to survive into the output, not just the prose here: separate sections, distinct labels, and —
proposed — **distinct exit codes**, so a CI pipeline can gate hard on document faults while treating package limits as a
softer signal. `0` clean, one code for faults, another for limits. The exact numbers are open; the fact that they differ
should not be.

The rule that follows from this: **a package limit is never reported as if the consumer made a mistake.** They wrote a
valid contract. We are the ones who cannot serve all of it yet, and the message says so.

## What it checks

Provisional, and expected to grow one section per honored construct:

| Section           | Answers                                                                                                                                                                                                                                                                       |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configuration     | Are the spec files found and readable? Which allowlist and options are in effect?                                                                                                                                                                                             |
| Document validity | Is this valid OpenAPI? The parser does not answer this in its library API — see [parser caveats](./openapi-support.md#parser-caveats) — so the doctor owns it.                                                                                                                |
| Version           | Which version was detected, and which [strategy](./openapi-support.md#handling-30-and-31-the-version-strategy) will handle it.                                                                                                                                                |
| References        | Unresolved `$ref`, references blocked by the [allowlist](./remote-references.md), and **`$ref` cycles — checked before the document reaches the parser**, which exhausts memory on them rather than raising. See the [parser caveats](./openapi-support.md#parser-caveats).   |
| Support findings  | Every `Partial`, `Ignored` and `Rejected` construct in the document, with its position.                                                                                                                                                                                       |
| Routing outcome   | The routes that will be registered, in order, with their targets — plus shadowing, where an earlier templated path swallows a later literal one.                                                                                                                              |
| Security          | Operations declaring `security` that the package does not enforce. This gets its own section rather than a line among others, because it is the one finding that can turn a documented-as-protected endpoint into a public one.                                               |
| Drift             | Whether the generated code still matches the specification. The runtime [cannot notice](./code-generation.md#the-runtime-never-sees-the-spec) that someone edited the spec and forgot to build, so this check is the only thing standing between that mistake and production. |
| Lifecycle         | The [`x-sunset` and `x-lifecycle` rules](./lifecycle.md#the-doctor-rules-that-follow), plus the coverage report: how many public operations are actually `stable`, and therefore how much of the API is protected at all.                                                     |
| Installation      | That the vendored directory is not gitignored, and that the generated path and namespace agree with what `composer` autoloads. Both are silent misconfigurations whose symptoms appear far from their cause.                                                                  |
| Artifact          | Whether the committed [contract artifact](../internals/contract-artifact.md) is current, and whether its format version predates the installed package.                                                                                                                       |

## Open questions on the doctor

- **The exit codes.** That document faults and package limits exit differently is settled. The numbers are not.
- **What still happens at boot.** `Rejected` fails at boot unless
  [acknowledged](#acknowledged-limits-the-consumers-opt-out), in which case the construct is skipped — the doctor is a
  check, not a substitute for refusing to load a spec the package cannot serve. Whether anything _below_ `Rejected`
  surfaces at boot at all, or whether the doctor is the only channel, is still open. Since the runtime loads generated
  PHP rather than a specification, "at boot" now means "baked into what the build emitted", which narrows the question
  rather than answering it.

When a command reference document exists, the usage details move there and this section keeps only the reasoning. It
lives here for now because the doctor is what makes the [support levels](./openapi-support.md#support-levels) mean
anything.

## Acknowledged limits: the consumer's opt-out

**Decision: a consumer can declare, in configuration, that they accept a limit — and the package then stops treating it
as a problem.**

Rule 2 assumes the reader can act on the diagnostic. Often they cannot. The spec comes from another team, from a vendor,
from a generator that always emits the same construct, and it is not theirs to change. For that developer a permanent,
unfixable warning is not information — it is a broken window, and the first thing they will look for is the switch that
turns the whole package quiet. Better to hand them a precise switch than to let them reach for a blunt one.

Acknowledgement is not the same as suppression, and the difference is the whole design:

- **It is enumerated, never global.** You list the specific constructs you accept. There is no "silence everything"
  option, because a spec that grows a new unhonored construct next month must still speak up — you never acknowledged
  _that_ one.
- **It stays visible.** Acknowledged items still appear in the doctor's report, in their own section, not folded into a
  count and not hidden. They stop _failing_; they do not stop _existing_. A configuration file nobody ever reads again
  is how accepted debt becomes forgotten debt.
- **Stale acknowledgements are themselves a finding.** When a construct you acknowledged no longer occurs in your spec,
  or the package has since grown support for it, the doctor says so, and you delete the line. Without this, the config
  only ever accumulates.
- **It is reviewable.** It lives in the application's config file, in version control, in diffs. The closest analogue in
  this ecosystem is a PHPStan baseline: an explicit, versioned list of accepted debt, where new violations still fail
  the build.

### Acknowledging changes behavior, not just noise

This is the part that must never be understated in the documentation we ship. Acknowledging a `Rejected` construct is
what allows the spec to load at all — so it is also the moment the construct is **dropped**. Acknowledge a `trace`
operation and the document still describes an endpoint that will answer 404. That is a legitimate choice, and it is the
consumer's to make, but the report has to state the consequence in those terms rather than reporting a clean bill of
health.

Which is why **security acknowledgements are never collapsed.** A consumer may accept that the package does not enforce
a declared security scheme — that is their call — but every affected operation is listed individually, on every run,
forever. This is the one place where being annoying is the correct behavior: the finding is that an endpoint the
contract describes as protected is not.

### Open questions on acknowledgement

- **Granularity.** Per construct (`trace: accepted` everywhere) covers the vendor-generator case that motivates the
  feature. Per construct _and_ location covers the one weird endpoint. Starting at the construct level and widening
  later is a **minor** release; the reverse is not.
- **Whether a reason string is required.** Requiring a justification on each entry is friction that pays for itself the
  day someone reads the config a year later and cannot remember why. It is also the kind of opinionated requirement
  [rule 3](./openapi-support.md#the-four-rules) invites.
- **The scope of a `Rejected` acknowledgement** — skipping the offending operation is the leading answer, with a
  `rejected_behavior: skip | fail` style option if the choice turns out to be worth giving away. Deliberately left to be
  settled against real code rather than in the abstract: the difference between the two only becomes concrete once there
  is a spec loader to watch.
- **The config keys themselves.** Public API surface. Not chosen.
