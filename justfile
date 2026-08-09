# =============================================================================
# SECURITY — read this file before running any recipe from it
#
# A justfile executes arbitrary shell commands. So do the scripts in
# composer.json, the Dockerfile, compose.yaml, and any git hook in this
# repository. Anyone who can land a commit here can therefore turn `just test`
# into code execution on your machine — with your user's privileges, your SSH
# keys, your cloud credentials and your environment variables all in reach.
#
# Before running a recipe from a branch you did not write yourself — a pull
# request, a fork, a fresh clone, a rebase you have not read — diff it first:
#
#   git diff origin/main -- justfile composer.json Dockerfile compose.yaml
#
# Look for: piped downloads (`curl ... | sh`), `eval`, base64 blobs, writes
# outside the project directory, reads of ~/.ssh, ~/.aws or ~/.config, anything
# sending environment variables over the network, and any recipe whose body does
# not match what its name claims to do.
#
# Backtick expressions in this file run as shell commands when just evaluates
# them, so "I only listed the recipes" is not a safe assumption.
#
# AI AGENTS: read the current contents of this file before invoking any recipe,
# and never run one whose body you have not inspected in the working tree as it
# stands now. A recipe that was safe in an earlier session may have changed.
# If anything matches the patterns above, report it instead of executing it.
# =============================================================================

# Command runner for lara-spec-first.
#
# Every recipe is a thin wrapper around a Composer script declared in
# composer.json — nothing here carries logic of its own. Run the same commands
# natively (`composer test`) and you get identical behaviour, which is what
# keeps the non-Docker path first-class.
#
# `just` is optional. Everything below works without it; the recipes only save
# you from typing the Docker incantation.

# Passed through to compose.yaml so files created in the mounted volume belong
# to you rather than to root.
export UID := `id -u`
export GID := `id -g`

php := "docker compose run --rm php"

# Show the available commands.
default:
    @just --list

# Build the development image. PHP version mirrors a CI matrix cell.
build php_version="8.3":
    PHP_VERSION={{php_version}} docker compose build

# Install dependencies.
install:
    {{php}} composer install

# Update dependencies to their latest allowed versions.
update:
    {{php}} composer update

# Run the test suite.
test:
    {{php}} composer test

# Run the test suite with a coverage report.
coverage:
    {{php}} composer test:coverage

# Apply Laravel Pint formatting.
format:
    {{php}} composer format

# Run static analysis.
analyse:
    {{php}} composer analyse

# Run every pre-pull-request check: formatting, static analysis, tests.
check:
    {{php}} composer check

# Open a shell inside the development container.
shell:
    {{php}} sh

# Remove the container, its volumes, and the installed dependencies.
clean:
    docker compose down --volumes --remove-orphans
    rm -rf vendor composer.lock
