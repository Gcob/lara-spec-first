<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Every response DTO a build will write.
 *
 * @internal Not public API.
 */
final readonly class ResponsePlan
{
    /**
     * @param  list<PlannedResponseDto>  $dtos  in the order they were first reached
     * @param  array<string, list<StatusResponse>>  $statuses  what each declared status answers with, by the
     *                                                         operation's label and in the order the document writes
     *                                                         them
     * @param  list<ResponseFallback>  $fallbacks  every status that has no DTO and every value that is `mixed` because
     *                                             a schema was at fault, in the order the build met them
     * @param  list<UntypedResponseValue>  $untyped  every value a DTO types `mixed`, or does not carry, for what the
     *                                               contract says, in the order the build met them: a different thing
     *                                               from a fallback, which is a schema at fault
     */
    public function __construct(
        public array $dtos = [],
        public array $statuses = [],
        public array $fallbacks = [],
        public array $untyped = [],
    ) {}
}
