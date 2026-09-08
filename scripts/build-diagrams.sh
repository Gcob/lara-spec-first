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

# Pinned deliberately, and by digest as well as by tag. A new PlantUML release can
# change layout, and discovering that as an unrelated diff in someone's pull
# request is the surprise this repository already avoids for Prettier and Pint.
# The tag alone would not be enough: a tag is mutable and can be repushed without
# anybody bumping anything, and `--check` compares byte for byte. The digest is
# the multi-arch index, so it resolves on an arm64 laptop and on an amd64 runner
# alike. The tag stays in front of it because it is the part a human reads.
# Bump both on purpose.
PLANTUML_IMAGE="plantuml/plantuml:1.2025.4@sha256:227c418ce3811b3bfd48e022922af35839914d606d5ea1c8a4137d77d58d482c"

SOURCE_DIR="docs/diagrams"

if [ ! -d "$SOURCE_DIR" ]; then
    echo "No $SOURCE_DIR directory, so there is nothing to render." >&2
    exit 0
fi

# Without this, an empty directory hands PlantUML the literal string
# "docs/diagrams/*.puml" and leaves --check reporting a stale "docs/diagrams/*.svg".
# A function so that `set --` rewrites the positional parameters of the function
# rather than the script's, which is where --check is read from.
have_sources() {
    set -- "$SOURCE_DIR"/*.puml
    [ -e "$1" ]
}

render_with_docker() {
    # The container runs as the invoking uid so that rendered files are not left
    # owned by root. That uid has no passwd entry, so the JVM resolves user.home
    # to "?" and Java writes its font cache to a directory of that name in the
    # working tree; JAVA_TOOL_OPTIONS is read before anything else and moves it.
    #
    # The output is mounted at a fixed path of its own, because --check renders
    # into a scratch directory outside the repository that a single
    # `$(pwd):/work` mount cannot reach.
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --volume "$(pwd):/work" \
        --volume "$1:/out" \
        --workdir /work \
        --env JAVA_TOOL_OPTIONS=-Duser.home=/tmp \
        "$PLANTUML_IMAGE" \
        -tsvg -nometadata -o /out "$SOURCE_DIR"/*.puml
}

render_with_local_plantuml() {
    # -nometadata keeps the PlantUML version out of the SVG. Without it every
    # render by a different version rewrites every file, and the diff says
    # nothing about the diagram.
    plantuml -tsvg -nometadata -o "$1" "$SOURCE_DIR"/*.puml
}

# The pinned image comes first, and the local binary is the fallback rather than
# the preference. Reversing the two was the original shape and it was wrong: a
# contributor with any PlantUML on PATH rendered with an unpinned version, and
# `--check` compares byte for byte. The version is not even the whole of it —
# reading-pipeline.puml asks for Helvetica, so the SVG's coordinates depend on
# the fonts the rendering JVM can see, and a local render on a machine without
# that font differs from the container's at the same PlantUML version.
render() {
    # Absolute: the docker path mounts it, and plantuml -o resolves a relative
    # path against the source file rather than against the working directory.
    OUTPUT=$(cd "$1" && pwd)

    if command -v docker > /dev/null 2>&1; then
        render_with_docker "$OUTPUT"
        return
    fi

    if command -v plantuml > /dev/null 2>&1; then
        echo "Docker is not available, so this rendered with the PlantUML on PATH" >&2
        echo "rather than $PLANTUML_IMAGE. Its version and its fonts decide the" >&2
        echo "output, so the result may differ from what CI expects." >&2
        render_with_local_plantuml "$OUTPUT"
        return
    fi

    echo "Neither Docker nor PlantUML is available, so diagrams cannot be rendered." >&2
    echo "Install Docker, or PlantUML, then run this again." >&2
    exit 1
}

if [ "${1:-}" = "--check" ]; then
    # Only the pinned image, unlike a write. This compares byte for byte, so a
    # render from an unpinned version or a different set of fonts would report a
    # drift that is not one, in the check whose whole job is to be trustworthy.
    if ! command -v docker > /dev/null 2>&1; then
        echo "Docker is required to check diagrams: the comparison is byte for byte," >&2
        echo "so it only trusts $PLANTUML_IMAGE." >&2
        exit 1
    fi

    STALE=""

    # Skipped when every source is gone, which is not the same as nothing to do:
    # the orphan pass below is exactly what has to run in that case.
    if have_sources; then
        SCRATCH=$(mktemp -d)
        trap 'rm -rf "$SCRATCH"' EXIT

        OUTPUT=$(cd "$SCRATCH" && pwd)
        render_with_docker "$OUTPUT"

        for rendered in "$SCRATCH"/*.svg; do
            committed="$SOURCE_DIR/$(basename "$rendered")"
            if [ ! -f "$committed" ] || ! cmp -s "$rendered" "$committed"; then
                STALE="$STALE $committed"
            fi
        done
    fi

    # The other direction, which the loop above cannot see: a committed SVG whose
    # source was deleted is produced by nothing and would stay green forever.
    ORPHANED=""
    for committed in "$SOURCE_DIR"/*.svg; do
        [ -e "$committed" ] || continue
        if [ ! -f "${committed%.svg}.puml" ]; then
            ORPHANED="$ORPHANED $committed"
        fi
    done

    if [ -n "$STALE" ]; then
        echo "These diagrams do not match their source. Run 'just diagrams':" >&2
        for file in $STALE; do echo "  $file" >&2; done
    fi

    if [ -n "$ORPHANED" ]; then
        echo "These diagrams have no source any more. Delete them, or restore the .puml:" >&2
        for file in $ORPHANED; do echo "  $file" >&2; done
    fi

    if [ -n "$STALE" ] || [ -n "$ORPHANED" ]; then
        exit 1
    fi

    echo "Every diagram matches its source."
    exit 0
fi

if ! have_sources; then
    echo "No PlantUML source under $SOURCE_DIR, so there is nothing to render." >&2
    exit 0
fi

render "$SOURCE_DIR"
