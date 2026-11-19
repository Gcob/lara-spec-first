#!/usr/bin/env sh
#
# Run the demo (`composer demo`, which runs demo/run.sh) inside the development
# container: `scripts/demo-in-docker.sh 0.2` or `scripts/demo-in-docker.sh 0.3 my-branch`.
# `just demo` calls it. It needs only `sh` and `docker` on the host, and its exit
# code is the demo's (0, 1 or 2).
#
# It exists for one case: a linked git worktree (`git worktree add`), where `.git`
# is a file that points at `<main checkout>/.git/worktrees/<name>`, a path outside
# what the container mounts. Git inside the container then says "not a git
# repository", and the demo needs git to read the tags and the branch. So here the
# main checkout's `.git` is mounted read-only at /git-common, and the demo is told
# where the worktree's own folder is under it.
#
# The two variables are named DEMO_GIT_DIR and DEMO_GIT_WORK_TREE, and not after
# git's own, because Composer clears git's two variables from the environment of
# every script it runs. demo/run.sh turns them back into git's variables.
#
# It passes `--build`, so an image built before the Dockerfile changed (one with
# no bash and no Hurl) is rebuilt instead of failing with exit 127; the layer
# cache makes it a no-op when nothing changed.
#
# It runs `docker compose run` itself, as format-markdown.sh runs `docker run`, so
# the `demo` recipe stays a one-line wrapper. The user is set here rather than read
# from the justfile's exported UID, so the script also works run by hand.

set -eu

cd "$(dirname "$0")/.."

if [ -f .git ]; then
    gitdir="$(sed -n 's/^gitdir: //p' .git)"
    gitdir="$(cd "$gitdir" && pwd -P)"
    common="$(cd "$gitdir/$(cat "$gitdir/commondir")" && pwd -P)"
    set -- --volume "$common:/git-common:ro" \
        --env "DEMO_GIT_DIR=/git-common/${gitdir#"$common"/}" \
        --env DEMO_GIT_WORK_TREE=/app \
        php composer demo -- "$@"
else
    set -- php composer demo -- "$@"
fi

exec docker compose run --build --rm --user "$(id -u):$(id -g)" "$@"
