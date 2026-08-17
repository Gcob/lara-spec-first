#!/usr/bin/env sh
#
# Reformat every Markdown file the repository tracks: reflow each paragraph to
# the column limit in .editorconfig, and align table columns.
#
# Prose is hard-wrapped in this repository so that a one-word change produces a
# one-line diff. The cost is that any edit to the middle of a paragraph — a
# renamed file in a link, a longer sentence — leaves the remaining lines ragged,
# and nothing but a reflow puts them back. Doing that by hand is wasted time and
# was the reason this script exists.
#
# Prettier is the tool because it unwraps and re-wraps in one pass
# (`proseWrap: always` in .prettierrc.json) and reads `max_line_length` straight
# from .editorconfig, so the column limit is never configured twice. Tables are
# padded as a side effect, which is the other half of the visual damage a link
# rewrite causes.
#
# It runs OUTSIDE the PHP container, unlike every other check: Prettier is a Node
# tool and the development image is php:8.3-cli-alpine, with no Node in it. Both
# entry points — `just format-md` and `composer format:md` — call this script, so
# there is still one implementation and the native path keeps matching.
#
# Prettier 3 honors .gitignore in addition to .prettierignore, so vendor/,
# node_modules/ and reviews/ are skipped without listing them here.
#
# Pass --check to report which files need reformatting without writing to them;
# it exits non-zero when any file differs.

set -eu

# Pinned deliberately. A new Prettier minor can change a formatting rule, and
# discovering that as an unrelated diff in someone's pull request is exactly the
# surprise scripts/check-lowest.sh avoids for Pint. Bump it on purpose.
PRETTIER="prettier@3.9.6"

TARGET="**/*.md"

MODE="--write"
if [ "${1:-}" = "--check" ]; then
    MODE="--list-different"
fi

if command -v npx > /dev/null 2>&1; then
    exec npx --yes "$PRETTIER" "$MODE" "$TARGET"
fi

# No Node on this machine. Borrow one for the duration of the command rather than
# asking a PHP contributor to install a JavaScript runtime. The container writes
# as the invoking user, so reformatted files are not left owned by root, and HOME
# points somewhere writable because npx caches the download there.
if command -v docker > /dev/null 2>&1; then
    exec docker run --rm \
        --user "$(id -u):$(id -g)" \
        --volume "$(pwd):/work" \
        --workdir /work \
        --env HOME=/tmp \
        node:22-alpine npx --yes "$PRETTIER" "$MODE" "$TARGET"
fi

echo "Neither Node nor Docker is available, so Markdown cannot be reformatted." >&2
echo "Install Node (any recent version) or Docker, then run this again." >&2
exit 1
