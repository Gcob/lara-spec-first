<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\Exceptions\EscapedTreeException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnwritableTreeException;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;

/**
 * A function with a static rather than a property on the test case: Pest closures
 * are bound to the test instance at runtime but typed as a pending call, so state
 * hung on `$this` is invisible to static analysis.
 */
function treeRoot(): string
{
    static $root = null;

    return $root ??= sys_get_temp_dir().'/lsf-tree-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(treeRoot()));
    mkdir(treeRoot(), 0o777, true);
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(treeRoot()));
});

function generated(string $path, string $body = 'body'): GeneratedFile
{
    return new GeneratedFile($path, "<?php\n// ".GeneratedFile::MARKER."\n".$body."\n");
}

it('writes each file under the root it was given', function (): void {
    $report = (new GeneratedTree(treeRoot()))->write([
        generated('routes.php'),
        generated('Controllers/GetUsersController.php'),
    ]);

    expect($report->written)->toBe(2)
        ->and(is_file(treeRoot().'/routes.php'))->toBeTrue()
        ->and(is_file(treeRoot().'/Controllers/GetUsersController.php'))->toBeTrue();
});

// The property the validation asks for: running it twice in a row changes
// nothing the second time. Not merely "produces the same bytes" — it does not
// touch the file at all, so a modification time stays where it was and a watcher
// downstream sees no event.
it('leaves an unchanged file alone on a second run', function (): void {
    $tree = new GeneratedTree(treeRoot());
    $files = [generated('routes.php')];

    $tree->write($files);
    touch(treeRoot().'/routes.php', 1000000);

    $second = $tree->write($files);

    expect($second->written)->toBe(0)
        ->and($second->unchanged)->toBe(1)
        ->and($second->changedNothing())->toBeTrue()
        ->and(filemtime(treeRoot().'/routes.php'))->toBe(1000000);
});

it('rewrites a file whose contents no longer match', function (): void {
    $tree = new GeneratedTree(treeRoot());

    $tree->write([generated('routes.php', 'before')]);
    $report = $tree->write([generated('routes.php', 'after')]);

    expect($report->written)->toBe(1)
        ->and(file_get_contents(treeRoot().'/routes.php'))->toContain('after');
});

// "Rewritten from scratch" has to mean the tree matches the contract rather than
// accumulating what it used to say. A controller for a removed operation would
// otherwise keep analysing and autocompleting forever.
it('removes a generated file the plan no longer contains', function (): void {
    $tree = new GeneratedTree(treeRoot());

    $tree->write([generated('Controllers/GoneController.php'), generated('routes.php')]);
    $report = $tree->write([generated('routes.php')]);

    expect($report->pruned)->toBe(1)
        ->and(is_file(treeRoot().'/Controllers/GoneController.php'))->toBeFalse();
});

// The invariant, from the other direction. The build owns files it wrote and
// nothing else, so a file somebody put in the generated tree survives — even
// though it should not be there. Deleting it would be exactly the "a build
// never destroys human work" rule broken by a tidying step.
it('never removes a file it did not write', function (): void {
    mkdir(treeRoot().'/Controllers', 0o777, true);
    file_put_contents(treeRoot().'/Controllers/Mine.php', "<?php\n// mine\n");

    $report = (new GeneratedTree(treeRoot()))->write([generated('routes.php')]);

    expect($report->pruned)->toBe(0)
        ->and(is_file(treeRoot().'/Controllers/Mine.php'))->toBeTrue();
});

// Stated without a clause on purpose, so it can be tested as one.
it('refuses to write outside the tree', function (string $path): void {
    expect(fn () => (new GeneratedTree(treeRoot()))->write([generated($path)]))
        ->toThrow(EscapedTreeException::class);

    // The refusal has to happen before anything is written, or it is a report
    // rather than a guard.
    expect(glob(treeRoot().'/*') ?: [])->toBe([]);
})->with([
    'climbing out' => '../escaped.php',
    'climbing out from a subdirectory' => 'Controllers/../../escaped.php',
    'absolute' => '/etc/escaped.php',
    'nothing at all' => '',
]);

/*
 * Permissions. Every case below is the same underlying scenario a container
 * makes ordinary: the build runs as one user and the tree belongs to another —
 * root inside Docker, or a deploy step, or a colleague's `sudo`.
 *
 * What is being pinned is not that the write fails. It is that it fails *loudly*.
 * PHP reports these by returning false and emitting a warning, so a build that
 * ignored the return value would count a file as written that is not on disk,
 * report success, and exit zero — leaving an application whose routes file
 * describes a contract nothing serves.
 */

/**
 * Run a callback with a path at a given mode, restoring it afterwards.
 *
 * The restore has to happen even when the assertion fails, or an unwritable
 * directory survives the test run and the temporary cleanup cannot remove it.
 */
function atMode(string $path, int $mode, Closure $body): void
{
    $previous = fileperms($path) & 0o777;
    chmod($path, $mode);

    try {
        $body();
    } finally {
        chmod($path, $previous);
    }
}

it('refuses when the tree itself cannot be written to', function (): void {
    atMode(treeRoot(), 0o500, function (): void {
        expect(fn () => (new GeneratedTree(treeRoot()))->write([generated('routes.php')]))
            ->toThrow(UnwritableTreeException::class, 'could not create the directory');
    });
});

it('refuses when a subdirectory cannot be created', function (): void {
    atMode(treeRoot(), 0o500, function (): void {
        expect(fn () => (new GeneratedTree(treeRoot()))->write([generated('Controllers/A.php')]))
            ->toThrow(UnwritableTreeException::class, 'could not create the directory');
    });
});

// The one the directory check alone would miss: the tree is writable, and the
// file inside it is not.
it('refuses when an existing file belongs to somebody else', function (): void {
    file_put_contents(treeRoot().'/routes.php', 'from a previous run');

    atMode(treeRoot().'/routes.php', 0o444, function (): void {
        expect(fn () => (new GeneratedTree(treeRoot()))->write([generated('routes.php')]))
            ->toThrow(UnwritableTreeException::class, 'could not write');
    });
});

// A refusal has to leave the tree as it was, which is the same promise the
// planner makes one step earlier. Half a build is worse than none.
it('writes nothing at all when one file cannot be written', function (): void {
    file_put_contents(treeRoot().'/routes.php', 'from a previous run');

    atMode(treeRoot().'/routes.php', 0o444, function (): void {
        try {
            (new GeneratedTree(treeRoot()))->write([
                generated('routes.php'),
                generated('Controllers/A.php'),
            ]);
        } catch (UnwritableTreeException) {
            // The point is what did not happen.
        }

        expect(is_file(treeRoot().'/Controllers/A.php'))->toBeFalse()
            ->and(file_get_contents(treeRoot().'/routes.php'))->toBe('from a previous run');
    });
});

// --- diff(): the read-only sibling write() computes the same way it always has ---

it('reports every file as new to write against an empty tree', function (): void {
    $report = (new GeneratedTree(treeRoot()))->diff([
        generated('routes.php'),
        generated('Controllers/GetUsersController.php'),
    ]);

    expect($report->toWrite)->toBe(['routes.php', 'Controllers/GetUsersController.php'])
        ->and($report->unchanged)->toBe([])
        ->and($report->toPrune)->toBe([])
        ->and($report->isClean())->toBeFalse();
});

it('never touches the disk', function (): void {
    $tree = new GeneratedTree(treeRoot());

    $tree->diff([generated('routes.php')]);

    expect(is_dir(treeRoot()))->toBeTrue()
        ->and(glob(treeRoot().'/*') ?: [])->toBe([]);
});

it('reports a clean diff exactly when write() would have changed nothing', function (): void {
    $tree = new GeneratedTree(treeRoot());
    $files = [generated('routes.php')];

    $tree->write($files);
    $report = $tree->diff($files);

    expect($report->toWrite)->toBe([])
        ->and($report->unchanged)->toBe(['routes.php'])
        ->and($report->toPrune)->toBe([])
        ->and($report->isClean())->toBeTrue();
});

it('reports a file whose contents no longer match as something to write, not as unchanged', function (): void {
    $tree = new GeneratedTree(treeRoot());

    $tree->write([generated('routes.php', 'before')]);
    $report = $tree->diff([generated('routes.php', 'after')]);

    expect($report->toWrite)->toBe(['routes.php'])
        ->and($report->unchanged)->toBe([]);
});

it('reports a generated file the plan no longer contains as prunable, without removing it', function (): void {
    $tree = new GeneratedTree(treeRoot());

    $tree->write([generated('Controllers/Gone.php')]);
    $report = $tree->diff([]);

    expect($report->toPrune)->toBe(['Controllers/Gone.php'])
        ->and($report->isClean())->toBeFalse()
        ->and(is_file(treeRoot().'/Controllers/Gone.php'))->toBeTrue();
});

// The invariant `write()` already keeps, asked of `diff()` too: a file inside
// the tree that does not carry the marker is not this build's to report on,
// prunable or otherwise.
it('never reports a file it did not write as prunable', function (): void {
    file_put_contents(treeRoot().'/routes.php', 'a file nobody generated');

    $report = (new GeneratedTree(treeRoot()))->diff([]);

    expect($report->toPrune)->toBe([]);
});

// A root passed in with a trailing separator must not throw off the prefix
// strip that turns an absolute path back into one relative to the root.
it('reports the correct relative path even when the root carries a trailing separator', function (): void {
    (new GeneratedTree(treeRoot()))->write([generated('Controllers/Gone.php')]);

    $report = (new GeneratedTree(treeRoot().'/'))->diff([]);

    expect($report->toPrune)->toBe(['Controllers/Gone.php']);
});
