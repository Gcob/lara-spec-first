<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;

/**
 * Finds the line an operation is written on, by reading the document as text.
 *
 * **Nothing here parses YAML, and that is the decision this class exists for.**
 * Parsing and re-emitting a specification destroys comments, key order and
 * anchors, in the one file a team reads in every pull request. YAML's indentation
 * is predictable enough that there is nothing to parse for: the operation's line
 * is findable, and one line goes in beneath it at the depth its siblings already
 * sit at.
 *
 * **It refuses far more readily than it guesses.** Every shape it cannot read with
 * certainty answers null, and the command then prints the row for a human to place
 * — a flow-style mapping, a JSON document, an operation reached through a `$ref`
 * into another file, a `paths` key that is not a block. Each of those is a document
 * this package can still build from; none of them is one it may edit blind.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */
final readonly class OperationLocator
{
    /**
     * Where the operation's own keys are written, or null when that cannot be
     * established.
     *
     * @param  string  $document  the specification exactly as it is on disk
     */
    public function locate(string $document, Operation $operation): ?OperationLocation
    {
        $lines = explode("\n", $document);

        $paths = $this->keyLine($lines, 'paths', 0, null);

        if ($paths === null) {
            return null;
        }

        $path = $this->keyLine($lines, $operation->path->template, $paths + 1, $this->indentOf($lines[$paths]));

        if ($path === null) {
            return null;
        }

        $method = $this->keyLine($lines, $operation->method->value, $path + 1, $this->indentOf($lines[$path]));

        if ($method === null) {
            return null;
        }

        $indentation = $this->childIndentation($lines, $method);

        if ($indentation === null) {
            return null;
        }

        // The line after the operation's own, 1-indexed: `$method` is a zero-based
        // array key, so `+ 1` is the operation's line number and the insertion goes
        // directly under it. Positive by construction, since a `paths` key had to be
        // found above it — the `> 0` below is PHPStan's `positive-int` narrowing
        // rather than a reachable false branch.
        $line = $method + 1;

        return $line > 0 ? new OperationLocation($line, $indentation) : null;
    }

    /**
     * The index of the line declaring a key, searching only that key's own level.
     *
     * **A mapping key with nothing after the colon**, because that is what makes
     * the block form the block form: `get: {responses: {}}` is the same document
     * and an entirely different editing problem, so it is not matched at all.
     *
     * The search stops at the first line that has climbed back out of the block,
     * which is what keeps `get` under `/users/{id}` from being found under
     * `/posts`.
     *
     * @param  list<string>  $lines
     * @param  int|null  $parentIndent  the indentation of the key this one sits
     *                                  under, or null at the document's root
     */
    private function keyLine(array $lines, string $key, int $from, ?int $parentIndent): ?int
    {
        $level = null;

        for ($index = $from; $index < count($lines); $index++) {
            $line = $lines[$index];

            if ($this->isBlank($line)) {
                continue;
            }

            $indent = $this->indentOf($line);

            if ($parentIndent !== null && $indent <= $parentIndent) {
                return null;
            }

            // The first key inside the block fixes the level being searched.
            // Anything deeper belongs to a sibling's subtree and is skipped rather
            // than matched: `x-controller` on another operation is at the same
            // depth as this one's `get`, and its children are not.
            $level ??= $indent;

            if ($indent > $level) {
                continue;
            }

            if ($this->declaredKey($line) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The key a line declares with nothing after its colon but blank space and,
     * optionally, a comment, or null.
     *
     * Quoting is stripped because a path key is routinely written `'/users/{id}':`
     * — YAML needs the quotes for a value starting with a character it reserves,
     * and the key is the same key either way.
     *
     * **A trailing comment is allowed, deliberately**, even though this class
     * otherwise refuses far more readily than it guesses. `get: # the list
     * endpoint` is a block mapping exactly as much as `get:` alone is — the
     * comment is not a value — and common enough on the very keys this locator
     * matches that refusing it would turn an ordinary annotation into a document
     * this package cannot edit for a reason the docblock does not warn about.
     */
    private function declaredKey(string $line): ?string
    {
        if (preg_match('/^\s*(?|"([^"]*)"|\'([^\']*)\'|([^:#]+?))\s*:\s*(?:#.*)?$/', $line, $found) !== 1) {
            return null;
        }

        return $found[1];
    }

    /**
     * The indentation the operation's own keys use, read from the first one.
     *
     * Taken from the document rather than computed from a step, because a
     * specification's indentation is its author's and a build has no business
     * normalizing it. Null when the operation has no keys to learn it from, which
     * is not a document this package would have got operations out of anyway.
     *
     * @param  list<string>  $lines
     */
    private function childIndentation(array $lines, int $method): ?string
    {
        $methodIndent = $this->indentOf($lines[$method]);

        for ($index = $method + 1; $index < count($lines); $index++) {
            $line = $lines[$index];

            if ($this->isBlank($line)) {
                continue;
            }

            $indent = $this->indentOf($line);

            if ($indent <= $methodIndent) {
                return null;
            }

            return substr($line, 0, $indent);
        }

        return null;
    }

    /**
     * How far a line is indented, in characters.
     *
     * Characters rather than a notion of levels: the insertion copies the
     * whitespace it found, so whether the document indents with two spaces, four,
     * or something stranger never has to be decided here.
     */
    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, " \t"));
    }

    /**
     * A line with nothing on it, or nothing but a comment.
     *
     * Skipped rather than treated as structure, because a comment sits at whatever
     * depth its author liked and a blank line has no depth at all — reading either
     * as a level would end the search inside the block it was looking through.
     */
    private function isBlank(string $line): bool
    {
        $trimmed = ltrim($line);

        return $trimmed === '' || str_starts_with($trimmed, '#');
    }
}
