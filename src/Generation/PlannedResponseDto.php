<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One response DTO the build will write, before it is written.
 *
 * @internal Not public API: what {@see ResponseDtoPlanner} hands the emitter.
 */
final readonly class PlannedResponseDto
{
    /**
     * @param  string  $shortName  the class name, `UserDto`
     * @param  string  $position  where the schema it describes is written
     * @param  list<InputDtoProperty>  $properties  the required ones first, then the optional ones, each group in the
     *                                              order the contract writes them
     * @param  list<array{label: string, status: string}>  $readers  the operations that answer with this type, in
     *                                                               document order, each with the status that does.
     *                                                               Empty for a type that is only the type of a
     *                                                               property of another DTO
     * @param  string  $naming  how the name was reached, for the file's findings
     * @param  list<string>  $findings  what the build worked out about the properties that the declared types do not
     *                                  show
     */
    public function __construct(
        public string $shortName,
        public string $position,
        public array $properties,
        public array $readers,
        public string $naming,
        public array $findings,
    ) {}

    public function relativePath(): string
    {
        return 'Data/'.$this->shortName.'.php';
    }
}
