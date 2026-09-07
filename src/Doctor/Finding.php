<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * One thing the doctor found, in one report format shared by all eight
 * sections — one place to add the next check, per docs/guide/doctor.md.
 *
 * `$pointer` is a JSON Pointer to the document position this finding names,
 * exactly as {@see docs/guide/code-generation/generated-file-anatomy.md#the-source-map} escapes one
 * — the same format rather than a second one invented for this report. It is
 * empty for a finding built straight from a `SpecException` the reading
 * pipeline collected: those exceptions carry their position in prose inside
 * `$message` today, not as structured data a pointer could be built from
 * without changing their constructors. A finding whose `$pointer` is empty
 * still names its position — read `$message`, which every one of them does
 * carry it in — this is a gap in *structure*, not in substance, and closing
 * it means widening `Parsing\Exceptions\` rather than anything in this
 * namespace.
 */
final readonly class Finding
{
    public function __construct(
        public FindingClass $class,
        public string $section,
        public ?SupportLevel $level,
        public string $pointer,
        public string $message,
    ) {}
}
