<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Whether the generated tree still matches what a build would emit — the
 * read-only sibling of {@see WriteReport}, produced by
 * {@see GeneratedTree::diff()} instead of {@see GeneratedTree::write()}.
 *
 * Every path is relative to the generated root, the same way
 * {@see GeneratedFile::$relativePath} is.
 */
final readonly class DriftReport
{
    /**
     * @param  list<string>  $toWrite  would be created or rewritten
     * @param  list<string>  $unchanged  already match what a build would emit
     * @param  list<string>  $toPrune  carry the generated marker and would be removed
     */
    public function __construct(
        public array $toWrite,
        public array $unchanged,
        public array $toPrune,
    ) {}

    public function isClean(): bool
    {
        return $this->toWrite === [] && $this->toPrune === [];
    }
}
