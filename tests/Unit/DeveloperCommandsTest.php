<?php

declare(strict_types=1);

/*
 * The justfile's own rule, asserted rather than trusted: every recipe is a thin
 * wrapper around a Composer script, so a contributor working natively runs exactly
 * the same command. It has been prose since the file was written, which means the
 * first recipe to grow logic of its own — or to name a script that was later
 * renamed — would break the promise while the suite stayed green.
 *
 * @see AGENTS.md — "Docker is a convenience, not the source of truth"
 */

/**
 * Every Composer script the justfile invokes.
 *
 * @return list<string>
 */
function recipeScripts(): array
{
    $justfile = file_get_contents(dirname(__DIR__, 2).'/justfile');
    assert(is_string($justfile));

    preg_match_all('/composer ([a-z][a-z0-9:-]*)/', $justfile, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * @return array<string, mixed>
 */
function composerScripts(): array
{
    $manifest = file_get_contents(dirname(__DIR__, 2).'/composer.json');
    assert(is_string($manifest));

    /** @var array{scripts: array<string, mixed>} $decoded */
    $decoded = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);

    return $decoded['scripts'];
}

it('finds the recipes it is about to check', function (): void {
    expect(recipeScripts())->not->toBeEmpty();
});

// Composer's own commands are the exception, and the only one: `composer install`
// is not a script this repository declares and never will be. Everything else the
// justfile types after `composer` has to be a script, comments included — a
// recipe's own documentation naming a command that no longer exists is the same
// defect as the recipe doing it.
it('names only Composer scripts that exist', function (): void {
    $scripts = composerScripts();
    $builtIn = ['install', 'update'];

    foreach (recipeScripts() as $script) {
        if (in_array($script, $builtIn, true)) {
            continue;
        }

        expect(array_key_exists($script, $scripts))
            ->toBeTrue("the justfile runs `composer {$script}`, which composer.json does not declare");
    }
});

// The Workbench's artisan, which is what makes `spec:build` and `spec:make`
// runnable by hand. Named here because a rename would leave `just artisan` and
// every documented `composer artisan --` invocation pointing at nothing.
it('keeps the Workbench artisan entry point', function (): void {
    expect(composerScripts())->toHaveKey('artisan');
});

// A script nobody can find is a script nobody runs. Composer prints these next to
// the script name, and the two lists drifting apart is exactly what a reader
// would never notice.
it('describes every script a contributor is meant to type', function (): void {
    $manifest = file_get_contents(dirname(__DIR__, 2).'/composer.json');
    assert(is_string($manifest));

    /** @var array{scripts: array<string, mixed>, scripts-descriptions: array<string, string>} $decoded */
    $decoded = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);

    // Composer's own lifecycle hooks and the internal steps they call are not
    // commands a contributor types, so they are exempt rather than described.
    $internal = ['post-autoload-dump', 'clear', 'prepare'];

    foreach (array_keys($decoded['scripts']) as $script) {
        if (in_array($script, $internal, true)) {
            continue;
        }

        expect(array_key_exists($script, $decoded['scripts-descriptions']))
            ->toBeTrue("the Composer script `{$script}` has no entry in scripts-descriptions");
    }
});
