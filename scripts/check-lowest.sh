#!/usr/bin/env sh
#
# Run the full check suite against the OLDEST *runtime* versions this package
# allows, then restore the newest.
#
# A normal install resolves to the newest supported Laravel, so anything that
# exists only there passes locally and breaks for everyone on the lower bound.
#
# The downgrade is scoped to the runtime packages on purpose. A bare
# `--prefer-lowest` would also pin the development tooling — Pint, Larastan,
# Pest — and then a minor Pint release that changes a formatting rule turns this
# red while the Laravel lower bound is perfectly healthy. A failure here has to
# mean "the lower bound is broken", or nobody will trust it. Users never see our
# Pint version; they do see our Laravel range.
#
# "Lowest" has to mean the floor composer.json declares, not whatever Composer
# is willing to install. Since Composer 2.9, `update` refuses any version that
# has a security advisory, and Laravel's older releases have some (12.0 to 12.70
# and 13.0 to 13.39 at the time of writing). So this script turns that blocking
# off for the downgrade, with the COMPOSER_NO_SECURITY_BLOCKING variable on that
# one command and for the whole dependency graph, because the floor's own tree
# carries advisories too (symfony/yaml among them). It is safe here: nothing is
# served from this install, the CI runner is discarded, and the restore below
# goes back to the newest versions with blocking on. A Composer older than 2.9
# ignores the variable, which is why it is not the `--no-security-blocking` flag.
#
# Three failure modes matter, and all are guarded below:
#
#   1. If the downgrade fails, the checks would run against the versions still
#      installed and report a pass that proves nothing.
#   2. If the downgrade succeeds but lands on another Laravel than the floor
#      (a dev package that now needs a newer one, or an advisory Composer still
#      refuses), the checks would verify a version nobody declared. The floor is
#      compared with what got installed, and a mismatch stops here.
#   3. If the checks fail, the restore must still run, or the working tree is
#      left silently on the lower bound for every command that follows.
#
# Invoked through `composer check:lowest`, which disables Composer's 300 second
# process timeout first — this script comfortably exceeds it, and being killed
# part way through would skip the restore that guard 2 exists to provide.

set -u

RUNTIME_PACKAGES="illuminate/routing illuminate/support symfony/yaml orchestra/testbench"

restore() {
    composer update --with-all-dependencies --no-interaction
}

# Composer refuses to update a partial set of packages when no lock file is
# present, and a library does not commit one. A fresh checkout — CI, or a clone
# nobody has installed yet — therefore reaches the downgrade below with nothing
# to narrow, and the run dies before a single check has been executed. Resolving
# the full set once first is what a contributor's `composer install` already did
# for them.
if [ ! -f composer.lock ]; then
    if ! composer update --no-interaction; then
        echo "" >&2
        echo "Could not resolve dependencies — nothing was verified." >&2
        exit 1
    fi
fi

# shellcheck disable=SC2086
if ! COMPOSER_NO_SECURITY_BLOCKING=1 composer update --prefer-lowest --prefer-stable \
    --with-all-dependencies --no-interaction $RUNTIME_PACKAGES; then
    echo "" >&2
    echo "Could not install the lowest supported versions — nothing was verified." >&2
    restore || echo "Restore also failed. Run 'composer update' before continuing." >&2
    exit 1
fi

# The floor is the first `^X.Y` of the constraint, read as X.Y.0: `^12.55 || ^13.0`
# is 12.55.0, and a leg of CI that pinned `^13.0` first is 13.0.0. The whole version
# is compared, so a dependency that needs a later patch of the floor's minor turns
# the run red instead of leaving the floor itself untested.
# `display_errors=0` keeps a deprecation notice from an old dependency out of the
# captured version; the notice still reaches stderr through PHP's error log.
floor=$(php -d display_errors=0 -r '
    $composer = json_decode(file_get_contents("composer.json"), true);
    preg_match("/\^([0-9]+\.[0-9]+)/", $composer["require"]["illuminate/support"] ?? "", $match);
    echo isset($match[1]) ? $match[1].".0" : "";
')
installed=$(php -d display_errors=0 -r '
    require "vendor/autoload.php";
    $version = ltrim(\Composer\InstalledVersions::getPrettyVersion("laravel/framework") ?? "", "v");
    echo $version;
')

if [ -z "$floor" ] || [ "$installed" != "$floor" ]; then
    echo "" >&2
    echo "The Laravel floor in composer.json is '$floor', but the lowest install got '$installed'. Nothing was verified." >&2
    echo "Either composer.json declares a floor nothing can install, or a dependency now needs a newer Laravel." >&2
    if [ -n "$floor" ] && [ -n "$installed" ] && php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$installed" "$floor"; then
        echo "Check your PHP version first: on PHP 8.4 and newer, Pest 5 and PHPUnit 13 keep Composer on a newer Laravel than the floor. CI runs PHP 8.3." >&2
    fi
    restore || echo "Restore also failed. Run 'composer update' before continuing." >&2
    exit 1
fi

composer check
status=$?

if ! restore; then
    echo "" >&2
    echo "Checks finished, but restoring the newest versions failed." >&2
    echo "You are still on the lowest supported versions. Run 'composer update'." >&2
    exit 1
fi

exit $status
