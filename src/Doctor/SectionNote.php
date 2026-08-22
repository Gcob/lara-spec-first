<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * What a section has to say beyond its findings: that it did not run, or that
 * what it did run on was narrower than the section's name promises.
 *
 * **Two states rather than one string, because the summary line has to tell
 * them apart.** doctor.md's rule is that "the report says which checks were
 * skipped, on every run, so a green exit is never mistaken for a full pass" —
 * so the sections that did not run are named again in the summary, where a
 * person skimming and a tailed CI log both land. A section that ran on a
 * partial document is not one of those: it has real findings, and listing it
 * as uncovered would understate them. Carrying `$checked` is what keeps one
 * mechanism from having to say both things with one sentence.
 *
 * @see docs/guide/doctor.md — "The contract"
 */
final readonly class SectionNote
{
    private function __construct(
        public bool $checked,
        public string $note,
    ) {}

    /**
     * The section did not run at all — its inputs were missing on this run, or
     * this release does not implement it.
     */
    public static function notChecked(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * The section ran, on less than it needed. Its findings stand; what they
     * cover is narrower than the section's name suggests.
     */
    public static function narrowed(string $reason): self
    {
        return new self(true, $reason);
    }
}
