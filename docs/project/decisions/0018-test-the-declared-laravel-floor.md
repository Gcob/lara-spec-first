---
title: "ADR 0018: The Laravel floor is the lowest version CI installs and tests"
audience: Users and contributors
covers: >
    The decision that the Laravel 12 floor is `^12.55`, that CI's lowest-dependencies run installs each declared floor
    (Laravel 12.55.0 and 13.0.0) exactly and fails when it gets anything else, that Composer's security blocking is off
    for that install only, that a unit test pins `composer.json` to the workflow, why the floor rose from 12.22, and the
    alternatives turned down.
read_before: >
    Asking why the package needs Laravel 12.55 and not 12.22, why `composer check:lowest` turns Composer's security
    blocking off, why the lowest job has one leg per Laravel major, or changing the Laravel range the package supports.
tags: [decisions, compatibility, laravel, versions]
---

# ADR 0018: The Laravel floor is the lowest version CI installs and tests

> **In brief**
>
> - The package requires `^12.55 || ^13.0`. 12.22 was never tested: the test harness cannot install a Laravel 12 older
>   than 12.55, and no generated rule needs anything older.
> - CI's lowest-dependencies run has one leg per Laravel major. Each installs its floor exactly (12.55.0 and 13.0.0) and
>   fails when Composer lands on another version.
> - Composer's security blocking is off for that install, and for the whole dependency graph, because Composer 2.9 and
>   newer refuses a Laravel with a known advisory and every release below 12.71 has one. It is a CI and contributor
>   check, not advice to an application.
> - A unit test pins `composer.json` and the workflow to one floor, so they cannot drift apart again.
> - Turned down: keeping 12.22 with a second, older harness, a floor of 12.40, and a floor that follows Composer's
>   security blocking.

**Status:** Accepted · **Card:** [#188](https://github.com/Gcob/lara-spec-first/issues/188) · **Pull request:**
[#215](https://github.com/Gcob/lara-spec-first/pull/215)

## Context

`0.2.0` shipped `^12.22 || ^13.0`, because [ADR 0007](./0007-keep-json-scalars-strict.md) needed `integer:strict`, which
Laravel reads from 12.22. [ADR 0016](./0016-keep-json-scalars-typed.md) then removed that rule from every generated
file, so no generated rule needs 12.22 any more, and it left the floor untested as an open consequence.

It had never been tested. The harness is Pest 4 with `orchestra/testbench ^10.11`, and testbench 10.11 requires Laravel
`^12.55`, so no job could install a Laravel 12 older than 12.55. The workflow said otherwise in a comment ("CI installs
from 12.55") that stopped being true in June: since the advisories of 2026-06-17 (`<12.60` and `<12.61.1`), Composer 2.9
and newer has refused, by default, every Laravel below the fixed release, and the lowest job quietly installed the
lowest one it was allowed. That was 12.69 after the advisory of 2026-09-29, and 12.71 after the one of 2026-11-13.

Nothing tested the Laravel 13 floor either. The lowest job ran over `^12.22 || ^13.0`, whose lowest is always Laravel
12, so no job ever installed 13.0.

The test harness has a measured limit too. On Laravel 12.22 the suite is green only with Pest 3, PHPUnit 11, testbench
10.5 and `symfony/console` 7.3, and PHPStan then finds six errors in the tests (Pest 3's `with()` types). No Pest 4
boots Laravel 12.22 or 12.23: PHPUnit 12.3.4 changed what Laravel's error handler must pass it, and Laravel fixed that
in 12.24.

## Decision

**The Laravel 12 floor rises to `^12.55`.** It is testbench 10.11's floor, so the development constraints the package
already declares install it, and the whole check suite (Pint, PHPStan and the tests) is green on 12.55.0. The constraint
is `^12.55 || ^13.0` for `illuminate/routing` and `illuminate/support`.

**The lowest job installs each declared floor exactly.** `composer check:lowest` runs once per Laravel major: a leg pins
`illuminate/routing` and `illuminate/support` to its major's floor (`^12.55` or `^13.0`) and downgrades, so the legs
install Laravel 12.55.0 and 13.0.0. The job's checks are named per leg, and `All checks passed` stays the one required
check.

**A guard stops the run when Composer lands elsewhere.** After the downgrade, `scripts/check-lowest.sh` compares the
installed `laravel/framework` with the floor `composer.json` declares, on major and minor, and stops with "nothing was
verified" (after the restore) when they differ. The check that used to pass over a version nobody declared now fails.

**Composer's security blocking is off for the downgrade, for the whole dependency graph.** Without it no floor below
12.71 can be installed, and the floor's own tree carries advisories too (`symfony/yaml` among them). The script sets
`COMPOSER_NO_SECURITY_BLOCKING=1` on that one command, as the variable and not the `--no-security-blocking` flag: a
Composer older than 2.9 ignores the variable and would refuse the flag. The restore keeps blocking on. It is safe where
it runs: nothing is served from the install and the CI runner is discarded.

**A unit test pins the files to one floor.** `SupportFloorTest` reads `composer.json` and `.github/workflows/tests.yml`
and fails, naming the file to fix, when routing and support declare different ranges, when a test cell installs a floor
the range does not declare, or when a declared floor has no lowest leg. It skips in the jobs that pin one Laravel in
`composer.json` first, where the test would read that pin, and runs in CI's `static` job
(`composer test -- --filter=SupportFloor`), which installs unpinned and so sees the declared range.

## Alternatives turned down

- **Keeping `^12.22` and testing it with a second harness.** The lowest job would install Pest 3, PHPUnit 11, testbench
  10.5 and `symfony/console` 7.3 for Laravel 12 only. A test written with a Pest 4 API would break only that job, six
  PHPStan errors would need test changes or an `analyse` skipped there, and `check:lowest` would have to downgrade the
  development tooling, which it keeps current on purpose. It would keep 12.22 to 12.54 for applications that Composer
  2.9 and newer already refuses, unless they turn blocking off.
- **A floor of `^12.40`**, the lowest the declared Pest `^4.7` reaches. It keeps one harness, but it widens testbench to
  `^10.2 || ^11.0`, which reads oddly beside the test cell's `^10.11`, for the same applications.
- **A floor that follows Composer's security blocking** (`^12.71 || ^13.40` today), with no switch in the script. Every
  Laravel advisory would turn the guard red on every pull request until a breaking bump landed: the advisory of
  2026-11-13 moved Composer's floor from 12.55 to 12.71 overnight, with no change in this repository.
- **Testing only the Laravel 12 floor.** The guard and the matrix cost some ten lines for the second leg, and the 13
  floor would stay a promise nothing tested.

## Consequences

- **Breaking, in a `0.x`:** an application on Laravel 12.22 to 12.54 cannot install the next release. The CHANGELOG says
  so, and the fix is to upgrade Laravel.
- **A floor rises only with a change that makes the lowest job install it.** A testbench release that raises its floor,
  or a development tool that drops it, turns the lowest job red by design, and the guard names both versions.
- **Composer refuses the floors by default, and this is stated, not hidden.** Today that is every release below 12.71
  and from 13.0 to 13.39. An application on Composer 2.9 or newer needs a Laravel without a known advisory to install
  the package, which is a reason to upgrade Laravel and not a limit of this package. The package's own check turns
  blocking off for itself only.
- **The Laravel 13 floor is tested in CI only.** Locally, `composer check:lowest` goes to the lowest of the whole range,
  which is Laravel 12, and reproducing the 13 leg needs the 13 constraint pinned, as
  [CONTRIBUTING](../../../CONTRIBUTING.md#your-code-runs-on-12-and-13) says.
- **`composer check:lowest` needs PHP 8.3, for either floor.** On PHP 8.4 and newer, Pest 5 and PHPUnit 13 keep Composer
  on Laravel 13.35, above both floors, and the guard stops the run. CI runs PHP 8.3, and the guard's message names the
  PHP version as the likely cause.
- **The test of the floor runs in the `static` job only.** The jobs that pin one Laravel skip it. A test cell that
  drifts from `composer.json`, a declared alternative with no leg, and routing and support ranges that differ are red
  there, and a drifted `lowest` leg is also red through the guard, which reads what Composer installed.
- **Earlier records keep their numbers.** ADR 0007's 12.22 and ADR 0016's "CI tests Laravel 12 from 12.55" are dated
  records: the second one is true again, and neither is superseded whole.
