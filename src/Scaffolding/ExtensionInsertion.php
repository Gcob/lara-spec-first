<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnverifiedInsertionException;
use Throwable;

/**
 * Writes one line into the specification, and proves it changed nothing else
 * first.
 *
 * **This is the only thing in the package that edits the source of truth**, which
 * is why it is the most cautious. Everything else reads the document; a build
 * refuses to touch it; and the reason this exists at all is that wanting a custom
 * controller and having to hand-edit YAML before anything can help you is friction
 * with no purpose.
 *
 * **The edit happens on a copy, and the copy is read back through the normal
 * pipeline.** Not a lighter check written for this occasion: the same reader, the
 * same guards, the same extractor the build uses. What comes out is compared
 * against what the original produced, and everything must be identical except the
 * extension just added. Only then does the copy replace the original.
 *
 * **If that comparison fails, nothing is written.** The command says the automatic
 * edit would have changed something it did not intend, and falls back to printing
 * the row for a human to place. That path should never run, which is exactly why it
 * has to exist: an automatic edit to the file a whole team reads is worth a check
 * that cannot be argued with.
 *
 * **The copy lives beside the original**, and that is not tidiness. A `$ref` is
 * resolved relative to the file that carries it, so a copy in the system's
 * temporary directory would resolve references differently — or fail to resolve
 * them at all — and the comparison would then be measuring the move rather than
 * the edit.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */
final readonly class ExtensionInsertion
{
    public function __construct(private RemoteReferenceGuard $remote) {}

    /**
     * Add `x-controller` to one operation, or write nothing at all.
     *
     * **`$value` is normalized here, once, for every caller.** A leading `\` is a
     * spelling {@see OperationExtractor} accepts and drops on read —
     * `ltrim($written, '\\')` — rather than refuses. Normalizing anywhere
     * upstream of this method (a proposal built from a configured
     * namespace, a value typed at a prompt) would still leave every other caller,
     * present or future, free to hand this method a value the extractor would
     * read back differently — which is exactly the mismatch
     * {@see self::assertOnlyTheExtensionChanged()} exists to catch, thrown as
     * "changed something else" for a spelling the document accepts. Normalizing
     * the one value every caller funnels through closes that for all of them at
     * once.
     *
     * @throws UnverifiedInsertionException
     */
    public function insert(
        string $specPath,
        Operation $operation,
        OperationLocation $location,
        string $value,
    ): void {
        $value = ltrim($value, '\\');

        $original = @file_get_contents($specPath);

        if ($original === false) {
            throw UnverifiedInsertionException::unreadable($specPath);
        }

        $copy = $this->copyPath($specPath);
        $edited = $this->withExtension($original, $location, $value);

        if (@file_put_contents($copy, $edited) === false) {
            throw UnverifiedInsertionException::unwritable($copy);
        }

        // The rename below replaces the specification with this copy, so the copy
        // has to carry the original's mode before that happens — otherwise the
        // file a team reads in every pull request would silently inherit whatever
        // mode a temp file gets instead of the one its owner set.
        $mode = @fileperms($specPath);

        if ($mode !== false) {
            @chmod($copy, $mode & 0o777);
        }

        try {
            $this->assertOnlyTheExtensionChanged($specPath, $copy, $operation, $value);

            // Renamed rather than written over: the move is atomic, so an
            // interrupted command cannot leave half a specification behind. The
            // file a team reads in every pull request is the last place to accept
            // a partial write.
            if (! @rename($copy, $specPath)) {
                throw UnverifiedInsertionException::unwritable($specPath);
            }
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    /**
     * The document with one line added, and nothing else touched.
     *
     * No dumper, no reflow, no reordering: the text is split at a line and the new
     * line is pushed in. Comments, key order and anchors survive because nothing
     * ever looked at them.
     *
     * **Split on `\r?\n` and rejoined with whichever ending the document actually
     * uses.** A bare `explode("\n", ...)` on a CRLF document leaves every line
     * carrying a trailing `\r`, so the inserted line — pushed in with none — is
     * the one line in the file with the wrong ending. The comparison in
     * {@see self::assertOnlyTheExtensionChanged()} would not catch it: the parsed
     * operations are identical either way, which is exactly the class of change
     * this method exists to avoid making unnoticed.
     */
    private function withExtension(string $document, OperationLocation $location, string $value): string
    {
        $ending = str_contains($document, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n/', $document) ?: [];

        array_splice($lines, $location->line, 0, [$location->indentation.'x-controller: '.$value]);

        return implode($ending, $lines);
    }

    /**
     * Read the copy the way the build reads a specification, and require the same
     * contract back.
     *
     * @throws UnverifiedInsertionException
     */
    private function assertOnlyTheExtensionChanged(
        string $specPath,
        string $copy,
        Operation $operation,
        string $value,
    ): void {
        $before = $this->operations($specPath);
        $after = $this->operations($copy);

        $expected = array_map(
            fn (Operation $original): Operation => $original->identity() === $operation->identity()
                ? $this->withController($original, $value)
                : $original,
            $before,
        );

        // Compared with `==` rather than field by field, which is the point of
        // `Contract\` being value objects: a new property added there is covered by
        // this check on the day it is added, where a hand-written comparison would
        // silently stop looking at everything it had not heard of.
        if ($after != $expected) {
            throw UnverifiedInsertionException::changedMoreThanTheExtension($operation->label());
        }
    }

    /**
     * @return list<Operation>
     *
     * @throws UnverifiedInsertionException
     */
    private function operations(string $path): array
    {
        try {
            $reader = new SpecDocumentReader(new VersionStrategyFactory, remote: $this->remote);
            $outcome = ReadOutcome::read($reader, $path);
        } catch (Throwable $failure) {
            // The reading pipeline itself no longer throws for a document fault
            // — see {@see ReadOutcome} — so reaching this `catch` at all would
            // now mean something this package did not anticipate. Kept as the
            // outer boundary regardless, the same way every command's own
            // `catch (SpecException)` stays in place: this is defence for a
            // failure mode outside the reading pipeline's own contract, not the
            // mechanism for the ordinary one below.
            throw UnverifiedInsertionException::unreadableAfterEditing($failure->getMessage());
        }

        if (! $outcome->isClean()) {
            // A document the pipeline will not fully read after the edit is a
            // failed insertion, not a failed build — reported as the former,
            // with the first fault's own message carried along because it says
            // what it found. This verification wants the same zero-tolerance
            // reading `spec:build` and `spec:make` want, not the doctor's
            // report-everything one: one fault here is already reason enough
            // to fall back to printing the row for a human to place.
            throw UnverifiedInsertionException::unreadableAfterEditing($outcome->faults[0]->getMessage());
        }

        return $outcome->operations;
    }

    private function withController(Operation $operation, string $value): Operation
    {
        return new Operation(
            index: $operation->index,
            method: $operation->method,
            path: $operation->path,
            operationId: $operation->operationId,
            tags: $operation->tags,
            audience: $operation->audience,
            lifecycle: $operation->lifecycle,
            deprecated: $operation->deprecated,
            sunset: $operation->sunset,
            security: $operation->security,
            controller: $value,
        );
    }

    /**
     * Where the copy goes: beside the original, named for what it is.
     *
     * The random suffix is not about collisions between users. It is about two runs
     * of this command in one directory, and about a copy left behind by a process
     * that died — a fixed name would make the second run read the first one's
     * leftovers as a specification.
     *
     * **Leading-dotted, on top of that.** *Beside* the original is not tidiness —
     * {@see self::insert()}'s own reasoning is that a `$ref` resolves relative to
     * the file that carries it, so a copy anywhere else would resolve references
     * differently. But *beside* also means a `spec/*.yaml` glob in an editor
     * watcher, a linter, or CI sees this file for however long the read-back
     * takes — a dotfile keeps it out of most such globs without moving it
     * anywhere.
     */
    private function copyPath(string $specPath): string
    {
        return dirname($specPath).DIRECTORY_SEPARATOR.'.'.basename($specPath)
            .'.lsf-insert-'.bin2hex(random_bytes(4)).'.tmp.yaml';
    }
}
