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
     * @param  list<PlannedRequest>  $requests  one per operation with something
     *                                          to validate, in the same order.
     *                                          Fewer than the controllers, and
     *                                          the difference is what the
     *                                          command reports: an operation
     *                                          stating nothing about its input
     *                                          gets no class rather than one
     *                                          enforcing nothing
     * @param  int  $unreadBodies  operations among those without a request
     *                             whose body is declared only in media types
     *                             this package does not read. Counted apart,
     *                             because "states nothing" is not true of them:
     *                             they state something this package cannot serve
     */
    public function __construct(
        public array $controllers,
        public array $files,
        public array $requests = [],
        public int $unreadBodies = 0,
    ) {}

    /**
     * How many operations state nothing a rule set could be built from.
     *
     * @see docs/guide/code-generation/request-validation.md — "Nothing to validate, no class"
     */
    public function withoutRequest(): int
    {
        return count($this->controllers) - count($this->requests);
    }

    /**
     * How many generated requests read something they could not translate.
     *
     * The count rather than the list, for the same reason the doctor counts a
     * `Deferred` construct: the findings themselves are in the file the reader
     * would open next, and reprinting them in a build summary is a wall of text
     * nobody reads twice.
     */
    public function withUnenforcedConstraints(): int
    {
        return count(array_filter(
            $this->requests,
            static fn (PlannedRequest $request): bool => $request->rules->findings !== [],
        ));
    }

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
