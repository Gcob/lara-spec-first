<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnverifiedInsertionException;
use Gcob\LaraSpecFirst\Scaffolding\ExtensionInsertion;
use Gcob\LaraSpecFirst\Scaffolding\OperationLocation;
use Gcob\LaraSpecFirst\Scaffolding\OperationLocator;

/*
 * The only thing in this package that writes to the source of truth, which is why
 * most of what is asserted here is that it did not.
 *
 * The edit happens on a copy, the copy is read back through the same reader,
 * guards and extractor the build uses, and the operations that come out have to be
 * identical to the originals but for the extension just added. Everything else
 * leaves the document exactly as it was.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */

function insertionDirectory(): string
{
    static $directory = null;

    return $directory ??= sys_get_temp_dir().'/lsf-insert-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(insertionDirectory()));
    mkdir(insertionDirectory(), 0o777, true);
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(insertionDirectory()));
});

/**
 * A document on disk, beside which the copy will be written.
 */
function documentWith(string $contents): string
{
    $path = insertionDirectory().'/openapi.yaml';

    file_put_contents($path, $contents);

    return $path;
}

function insertionFixture(): string
{
    return documentWith(<<<'YAML'
        # The contract, with a comment nothing is allowed to lose.
        openapi: 3.0.3
        info: { title: Fixture, version: 1.0.0 }
        paths:
            /users/{id}:
                # An operation, also commented.
                get:
                    operationId: showUser
                    responses:
                        '200': { description: A user }
            /posts:
                get:
                    operationId: listPosts
                    responses:
                        '200': { description: Posts }
        YAML);
}

/**
 * @return list<Operation>
 */
function operationsIn(string $path): array
{
    return (new OperationExtractor)->extract((new SpecDocumentReader)->read($path));
}

function insertInto(string $path, int $index, ?OperationLocation $location = null): void
{
    $operation = operationsIn($path)[$index];
    $located = $location ?? (new OperationLocator)->locate((string) file_get_contents($path), $operation);

    if ($located === null) {
        throw new RuntimeException('the fixture operation could not be located');
    }

    (new ExtensionInsertion(new RemoteReferenceGuard))->insert(
        $path,
        $operation,
        $located,
        'App\\Http\\Controllers\\ShowUserController',
    );
}

// Normalized inside `insert()` itself rather than trusted from the caller, so that
// every caller — the interactive prompt, the `--yes` path, or one this package does
// not have yet — gets the same answer without having to remember to ask. A caller
// that passed the raw, backslash-led value used to fail its own verification: the
// extractor drops the leading `\` on read, so the copy-and-compare saw a change
// that was not the extension and refused the whole insertion.
it('normalizes a leading separator on the value itself, regardless of the caller', function (): void {
    $path = insertionFixture();
    $operation = operationsIn($path)[0];
    $location = (new OperationLocator)->locate((string) file_get_contents($path), $operation);

    if ($location === null) {
        throw new RuntimeException('the fixture operation could not be located');
    }

    (new ExtensionInsertion(new RemoteReferenceGuard))->insert(
        $path,
        $operation,
        $location,
        '\\App\\Http\\Controllers\\ShowUserController',
    );

    expect(file_get_contents($path))
        ->toContain('x-controller: App\\Http\\Controllers\\ShowUserController')
        ->and(operationsIn($path)[0]->controller)->toBe('App\\Http\\Controllers\\ShowUserController');
});

it('adds the row under the operation, at its own indentation', function (): void {
    $path = insertionFixture();

    insertInto($path, 0);

    expect(file_get_contents($path))
        ->toContain("        get:\n            x-controller: App\\Http\\Controllers\\ShowUserController\n");
});

// No dumper, no reflow: the text is split at a line and the new line is pushed in,
// so everything a YAML round-trip would have destroyed is simply never looked at.
it('leaves the comments and the key order exactly as they were', function (): void {
    $path = insertionFixture();
    $before = file_get_contents($path);

    insertInto($path, 0);

    $after = (string) file_get_contents($path);

    expect($after)
        ->toContain('# The contract, with a comment nothing is allowed to lose.')
        ->toContain('# An operation, also commented.')
        ->and(substr_count($after, "\n"))->toBe(substr_count((string) $before, "\n") + 1);
});

it('adds the extension to the operation it was asked about, and no other', function (): void {
    $path = insertionFixture();

    insertInto($path, 0);

    $operations = operationsIn($path);

    expect($operations[0]->controller)->toBe('App\\Http\\Controllers\\ShowUserController')
        ->and($operations[1]->controller)->toBeNull();
});

it('changes nothing else about the operations the contract describes', function (): void {
    $path = insertionFixture();
    $before = operationsIn($path);

    insertInto($path, 0);

    $after = operationsIn($path);

    expect(array_map(fn (Operation $o): string => $o->identity(), $after))
        ->toBe(array_map(fn (Operation $o): string => $o->identity(), $before))
        ->and($after[0]->operationId)->toBe($before[0]->operationId)
        ->and($after[0]->tags)->toBe($before[0]->tags);
});

// Split on `\n` alone leaves every line of a CRLF document carrying a trailing
// `\r`, and pushing in a bare line with none would make the inserted row the one
// line in the file with the wrong ending — invisible to the copy-and-compare
// verification, because the parsed operations are identical either way.
it('keeps a CRLF document entirely CRLF, including the inserted row', function (): void {
    $path = documentWith(implode("\r\n", [
        'openapi: 3.0.3',
        'info: { title: Fixture, version: 1.0.0 }',
        'paths:',
        '    /users/{id}:',
        '        get:',
        '            operationId: showUser',
        '            responses:',
        "                '200': { description: A user }",
        '',
    ]));

    insertInto($path, 0);

    $after = (string) file_get_contents($path);

    expect(substr_count($after, "\r\n"))->toBe(substr_count($after, "\n"))
        ->and($after)->toContain("x-controller: App\\Http\\Controllers\\ShowUserController\r\n");
});

// The rename that lands the copy over the original would otherwise hand the file
// whatever mode a freshly created temp file gets, rather than the mode its owner
// set — silent on a spec at `0600`, or one owned by someone else in a shared
// checkout.
it('keeps the mode the specification had before the edit', function (): void {
    $path = insertionFixture();
    chmod($path, 0o640);

    insertInto($path, 0);

    expect(fileperms($path) & 0o777)->toBe(0o640);
});

describe('when the edit cannot be proven', function (): void {
    // The check that cannot be argued with, exercised by pointing the insertion at
    // the wrong line: the row lands inside another operation, so the contract that
    // comes back is not the one the command intended.
    it('writes nothing when the row would land on another operation', function (): void {
        $path = insertionFixture();
        $before = file_get_contents($path);

        // Line 12 is `/posts`'s own `get:`, so the row lands on that operation
        // instead — a document that still reads, and a contract that is not the one
        // the command intended.
        expect(fn () => insertInto($path, 0, new OperationLocation(12, '            ')))
            ->toThrow(UnverifiedInsertionException::class, 'would have changed something else')
            ->and(file_get_contents($path))->toBe($before);
    });

    // The other half: an indentation that makes the document unreadable. The reader
    // refuses it, and the refusal is reported as a failed insertion rather than as a
    // failed build — with the reader's own words, because they say what it found.
    it('writes nothing when the document would no longer read', function (): void {
        $path = insertionFixture();
        $before = file_get_contents($path);

        expect(fn () => insertInto($path, 0, new OperationLocation(7, '')))
            ->toThrow(UnverifiedInsertionException::class, 'could not be read back')
            ->and(file_get_contents($path))->toBe($before);
    });

    // And no copy is left behind either way, which matters because a copy beside the
    // specification is a file that looks like one. Checked with both patterns
    // because the copy is now leading-dotted, and a plain `*.tmp.yaml` glob would
    // not see it whether it was cleaned up or not.
    it('leaves no copy beside the specification', function (): void {
        $path = insertionFixture();

        try {
            insertInto($path, 0, new OperationLocation(7, ''));
        } catch (UnverifiedInsertionException) {
            // The point is what is not on disk afterwards.
        }

        expect(array_merge(
            glob(insertionDirectory().'/*.tmp.yaml') ?: [],
            glob(insertionDirectory().'/.*.tmp.yaml') ?: [],
        ))->toBe([]);
    });
});

// A successful insertion leaves the tree with the specification and nothing else:
// the copy is renamed over it, so a directory listing after a normal run is the
// same proof that the temporary file — dotfile or not — never lingers.
it('leaves nothing beside the specification after a successful insertion', function (): void {
    $path = insertionFixture();

    insertInto($path, 0);

    expect(glob(insertionDirectory().'/*') ?: [])->toBe([$path]);
});
