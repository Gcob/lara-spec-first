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
# Two failure modes matter, and both are guarded below:
#
#   1. If the downgrade fails, the checks would run against the versions still
#      installed and report a pass that proves nothing.
#   2. If the checks fail, the restore must still run, or the working tree is
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

# shellcheck disable=SC2086
if ! composer update --prefer-lowest --prefer-stable --with-all-dependencies \
    --no-interaction $RUNTIME_PACKAGES; then
    echo "" >&2
    echo "Could not install the lowest supported versions — nothing was verified." >&2
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
