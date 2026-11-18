#!/usr/bin/env sh
#
# Format the one file Claude Code has just edited or written.
#
# Run by the PostToolUse hook in .claude/settings.json, on `Edit|Write`. Claude
# Code hands the hook a JSON document on stdin; the edited file is
# `tool_input.file_path`. A PHP file goes through Pint and then the docblock
# formatter (`composer format`, for one file), a Markdown file through
# scripts/format-markdown.sh, and anything else is left alone.
#
# It must never get in the way of the edit, so it always exits 0. A formatter
# that fails, a file that is mid-edit and does not parse, a tool that is not
# installed: each is printed to stderr and skipped. Fixing what the editor left
# unformatted is what `composer format` and the pre-commit hook are for.
#
# Only files inside the project are touched. The path comes from the payload, so
# it is checked rather than trusted: an absolute path elsewhere, or a file that
# does not exist, is ignored.
#
# Reading the JSON uses jq when it is installed and PHP otherwise; one of the two
# is on every machine this repository is developed on.

PROJECT=${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}
PROJECT=$(cd "$PROJECT" 2> /dev/null && pwd -P) || exit 0

payload=$(cat)

if command -v jq > /dev/null 2>&1; then
    file=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty' 2> /dev/null)
elif command -v php > /dev/null 2>&1; then
    file=$(printf '%s' "$payload" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? ($d["tool_input"]["file_path"] ?? "") : "";' 2> /dev/null)
else
    echo "format-edited-file: neither jq nor php is installed, so the edited file was not formatted." >&2
    exit 0
fi

[ -n "$file" ] && [ -f "$file" ] || exit 0

# Resolve symlinks and `..` before comparing with the project root.
directory=$(cd "$(dirname "$file")" 2> /dev/null && pwd -P) || exit 0
file="$directory/$(basename "$file")"

case "$file" in
    "$PROJECT"/*) relative=${file#"$PROJECT"/} ;;
    *) exit 0 ;;
esac

cd "$PROJECT" || exit 0

case "$relative" in
    # pint.json excludes this tree, but Pint only applies an exclusion when it
    # walks a directory: a file named on the command line is formatted anyway. The
    # build owns this tree, so skip it here rather than fight the build.
    workbench/app/Http/Generated/*) ;;
    # A minor's demo is frozen once released, so a formatter upgrade must not edit
    # it (pint.json, .prettierignore and the docblock formatter skip it too).
    # `demo/README.md` has no second slash, so it still gets formatted below.
    demo/*/*) ;;
    *.php)
        if [ -x vendor/bin/pint ]; then
            if ! output=$(vendor/bin/pint "$relative" 2>&1); then
                echo "format-edited-file: Pint failed on $relative:" >&2
                printf '%s\n' "$output" >&2
            fi
        else
            echo "format-edited-file: vendor/bin/pint is missing, run composer install. Skipping Pint." >&2
        fi

        if command -v php > /dev/null 2>&1; then
            php scripts/format-docblocks.php "$relative" > /dev/null || echo "format-edited-file: the docblock formatter failed on $relative." >&2
        fi
        ;;
    *.md)
        if command -v npx > /dev/null 2>&1; then
            scripts/format-markdown.sh "$relative" > /dev/null || echo "format-edited-file: Prettier failed on $relative." >&2
        else
            echo "format-edited-file: Node is not installed, so $relative was not formatted." >&2
        fi
        ;;
esac

exit 0
