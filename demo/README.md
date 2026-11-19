# The demo

A small events and RSVPs API, built in a brand-new Laravel app from nothing but an OpenAPI contract and the code a
developer writes on top of what `lara-spec-first` generates. Organizers create events, members RSVP. It is both a tour
of what the package does and the last check before a release: if the demo breaks, the release is not ready.

## Run it

```bash
just demo 0.2                      # the 0.2 demo, against 0.2.* from Packagist
just demo 0.3 release/0.3.0        # a minor not released yet: name the local branch to test
```

`just demo` is the way to run it on your machine: it needs only Docker with Compose and `just`. It builds the
development image once (PHP 8.3, Composer and [Hurl](https://hurl.dev/), at the version CI installs) and runs
`composer demo` in it. A run prints the PHP and Hurl versions it found. It works from a linked git worktree as well as
from a plain clone. Composer prints a note that it could not detect the root package version and defaults to `1.0.0`: it
is expected and harmless.

It creates a Laravel app in a temporary directory, requires the package, builds the contract, scaffolds every
controller, serves the app, and runs the Hurl scenarios against it. Nothing is left behind. It exits `0` when everything
passes, `1` when a check failed, and `2` when it could not get as far as checking.

CI does not use the container. The workflow in `.github/workflows/demo.yml` installs Hurl, runs `demo/run.sh` natively
on every folder when `demo/`, `src/`, `config/` or `composer.json` changes, and runs it every Monday. To do the same on
your machine, `demo/run.sh 0.2` (or `demo/run.sh 0.3 release/0.3.0`) needs `php`, `composer`, `curl`, `git`, `tar` and
Hurl (`brew install hurl` on macOS, or [another way](https://hurl.dev/docs/installation.html)) on the `PATH`, with PHP's
`pdo_sqlite` extension, and the network for Packagist.

A branch is installed from an archive of the branch made with `git archive`, which leaves out what a tag leaves out
(`export-ignore` in `.gitattributes`), so only committed work is tested and the package is what a release ships. A
branch run then checks it: it prints `installed gcob/lara-spec-first dev-<branch> (commit <short>)`, and a
`vendor/ holds none of the N files a tag leaves out` line, which fails the run if any of those files reached `vendor/`.

## One folder per minor

`demo/<minor>/` is a complete demo pinned to one minor release:

| File or folder    | What it holds                                                                 |
| ----------------- | ----------------------------------------------------------------------------- |
| `openapi.yaml`    | The contract, copied to the app's `openapi.yaml`.                             |
| `app/`            | The controllers and models a developer writes. Copied over the scaffolds.     |
| `database/`       | The migrations. Copied in, then `php artisan migrate`.                        |
| `scenarios/`      | The Hurl files, run in name order. Only `01-before-the-code.hurl` runs first. |
| `laravel-version` | The Laravel skeleton the folder was written for, one Composer constraint.     |
| `doctor-exit`     | The exit code `spec:doctor` gives this contract on this minor, one line.      |

A new minor's folder starts as a copy of the previous one, then gets that minor's features and its corrections. A
released minor's folder is frozen, like an entry in a journal: it keeps describing the package as it was, and it still
runs against its own version (`0.2.*` for `demo/0.2/`), so a patch release is checked by the folder of its minor.

Why one folder per minor, and not one demo that is updated:

- Everything the previous demo checked is still checked, unless the new folder changes it on purpose, so a minor cannot
  quietly drop what the one before it did.
- The difference between two folders is exactly what the minor changed. The evolution of the package reads without git.
- Each folder's comments describe the package as it was then, so a reader of `demo/0.2/` is not told about features that
  did not exist yet.

The folders stay out of the formatters (Pint, the docblock formatter and Prettier) and out of the documentation site for
the same reason: a tool or a rule written after a folder was frozen must not edit it. The demo is also left out of the
Composer package (`export-ignore`), so it never reaches a user's `vendor/` directory.

## Why Hurl, and not Pest

Pest is the Laravel way to test an application, and this repository's own tests use it. Here the aim is a demo, and a
scenario in Hurl reads like one: a plain-text HTTP request, then what the answer must be. Hurl is an external binary,
not a Composer dependency, so `run.sh` checks that it is installed and CI installs it.

## The folders

- [`0.2/`](./0.2): the first demo. Six operations: events with a filtered list and a cover upload, and RSVPs with a full
  event refused. Validated before any code exists.
