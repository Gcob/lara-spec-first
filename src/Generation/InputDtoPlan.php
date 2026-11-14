<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Every input DTO a build will write, and which one each operation reads.
 *
 * @internal Not public API.
 */
final readonly class InputDtoPlan
{
    /**
     * @param  list<PlannedInputDto>  $dtos  in the order they were first reached
     * @param  array<string, string>  $readBy  an operation's label mapped to the
     *                                         short name of the DTO its request
     *                                         hands back. Absent for an operation
     *                                         with no body, or whose body is not
     *                                         an object
     */
    public function __construct(
        public array $dtos = [],
        public array $readBy = [],
    ) {}
}
