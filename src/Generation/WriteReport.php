<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What a build actually did to the working tree.
 *
 * Reported rather than counted for its own sake: `unchanged` is what makes
 * idempotence visible from the outside. A second run in a row should report every
 * file unchanged and nothing written, and a run that says otherwise on an
 * untouched specification is a defect worth seeing without diffing.
 */
final readonly class WriteReport
{
    public function __construct(
        public int $written,
        public int $unchanged,
        public int $pruned,
    ) {}

    public function changedNothing(): bool
    {
        return $this->written === 0 && $this->pruned === 0;
    }
}
