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
     */
    public function __construct(public array $dtos = []) {}
}
