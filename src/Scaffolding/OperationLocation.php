<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

/**
 * Where in the specification's own text an operation is written.
 *
 * Two facts, and both are needed to add a line without reformatting anything
 * around it: which line the new one goes after, and how far in it has to sit.
 */
final readonly class OperationLocation
{
    /**
     * @param  positive-int  $line  the operation's own line, 1-indexed the way an
     *                              editor counts — the insertion goes directly under it
     * @param  string  $indentation  the whitespace the operation's own keys carry,
     *                               taken from the document rather than assumed, so a
     *                               two-space file does not gain a four-space line
     */
    public function __construct(
        public int $line,
        public string $indentation,
    ) {}
}
