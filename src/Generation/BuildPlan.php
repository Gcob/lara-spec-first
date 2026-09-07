<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Everything a build worked out, before any of it is written.
 *
 * The files are what gets written; the controllers are what the command has to be
 * able to *say* something about. Returning only the files would leave the command
 * counting operations and asking the filesystem the same questions the plan
 * already answered — and a second answer is a second chance to contradict the
 * first.
 *
 * @see docs/guide/code-generation/index.md — "The build command: spec:build"
 */
final readonly class BuildPlan
{
    /**
     * @param  list<PlannedController>  $controllers  in document order
     * @param  list<GeneratedFile>  $files  every file the build intends to write
     */
    public function __construct(
        public array $controllers,
        public array $files,
    ) {}

    /**
     * How many operations the route sends to their generated parent.
     *
     * **Named for what it measures rather than for what the caller does with it.**
     * Today a generated parent answers 501 and nothing else, so this count and
     * "how many operations are unimplemented" are the same number — and calling
     * it `unimplemented()` would bake that coincidence into the name. The moment
     * a generated controller can answer an operation itself, from a CRUD default
     * the build derived, the coincidence ends: those operations would still route
     * to their generated parent and would no longer be unimplemented. The claim
     * about 501 therefore belongs to the command writing the message, where it is
     * one sentence to revisit rather than a method name every caller believed.
     *
     * An operation whose custom controller exists is not counted, whatever that
     * class does inside — the build cannot judge that, and should not try.
     */
    public function routedToGeneratedParent(): int
    {
        return count(array_filter(
            $this->controllers,
            static fn (PlannedController $controller): bool => ! $controller->routesToCustomController(),
        ));
    }
}
