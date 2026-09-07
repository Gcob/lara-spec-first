#!/usr/bin/env sh
#
# Render every PlantUML source under docs/diagrams/ to an SVG beside it.
#
# Diagrams are pre-rendered and committed rather than rendered by the site,
# because a Markdown page then only ever references an image. That is what keeps
# a diagram readable on GitHub as well as on the documentation site, neither of
# which renders PlantUML on its own, and it is what leaves the source syntax free
# to change later without touching a single page.
#
# The cost of committing generated output is that the source and the SVG can
# drift apart. `--check` is the answer: it re-renders into a scratch directory
# and exits non-zero when the committed SVG differs, so CI catches a diagram
# edited without being rebuilt, exactly as `format:check` catches unformatted
# prose.
#
# It runs OUTSIDE the PHP container, like Markdown formatting and for the same
# reason: PlantUML is a Java tool and the development image is php:8.3-cli-alpine.
#
# Pass --check to compare without writing.

set -eu

# Pinned deliberately. A new PlantUML release can change layout, and discovering
# that as an unrelated diff in someone's pull request is the surprise this
# repository already avoids for Prettier and Pint. Bump it on purpose.
PLANTUML_IMAGE="plantuml/plantuml:1.2025.4"

SOURCE_DIR="docs/diagrams"

if [ ! -d "$SOURCE_DIR" ]; then
    echo "No $SOURCE_DIR directory, so there is nothing to render." >&2
    exit 0
fi

render() {
    # Absolute, and mounted at a fixed path of its own below. `--check` renders
    # into a scratch directory outside the repository, which a single
    # `$(pwd):/work` mount cannot reach.
    OUTPUT=$(cd "$1" && pwd)

    # -nometadata keeps the PlantUML version out of the SVG. Without it every
    # render by a different version rewrites every file, and the diff says
    # nothing about the diagram.
    if command -v plantuml > /dev/null 2>&1; then
        plantuml -tsvg -nometadata -o "$OUTPUT" "$SOURCE_DIR"/*.puml
        return
    fi

    # The container runs as the invoking uid so that rendered files are not left
    # owned by root. That uid has no passwd entry, so the JVM resolves user.home
    # to "?" and Java writes its font cache to a directory of that name in the
    # working tree; JAVA_TOOL_OPTIONS is read before anything else and moves it.
    if command -v docker > /dev/null 2>&1; then
        docker run --rm \
            --user "$(id -u):$(id -g)" \
            --volume "$(pwd):/work" \
            --volume "$OUTPUT:/out" \
            --workdir /work \
            --env JAVA_TOOL_OPTIONS=-Duser.home=/tmp \
            "$PLANTUML_IMAGE" \
            -tsvg -nometadata -o /out "$SOURCE_DIR"/*.puml
        return
    fi

    echo "Neither PlantUML nor Docker is available, so diagrams cannot be rendered." >&2
    echo "Install PlantUML, or Docker, then run this again." >&2
    exit 1
}

if [ "${1:-}" = "--check" ]; then
    SCRATCH=$(mktemp -d)
    trap 'rm -rf "$SCRATCH"' EXIT

    render "$SCRATCH"

    STALE=""
    for rendered in "$SCRATCH"/*.svg; do
        committed="$SOURCE_DIR/$(basename "$rendered")"
        if [ ! -f "$committed" ] || ! cmp -s "$rendered" "$committed"; then
            STALE="$STALE $committed"
        fi
    done

    if [ -n "$STALE" ]; then
        echo "These diagrams do not match their source. Run 'just diagrams':" >&2
        for file in $STALE; do echo "  $file" >&2; done
        exit 1
    fi

    echo "Every diagram matches its source."
    exit 0
fi

render "$SOURCE_DIR"
