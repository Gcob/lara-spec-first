<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One input DTO the build will write, before it is written.
 *
 * @internal Not public API: what {@see InputDtoPlanner} hands the emitter.
 */
final readonly class PlannedInputDto
{
    /**
     * @param  string  $shortName  the class name, `NewUserInputDto` or
     *                             `NewUserPartialInputDto`
     * @param  bool  $partial  whether every property is optional
     * @param  string  $position  where the schema it describes is written: a
     *                            component's own position, or the operation's for
     *                            a schema written inline
     * @param  list<InputDtoProperty>  $properties
     * @param  list<string>  $readers  the labels of the operations whose
     *                                 `dto()` returns this type, in document
     *                                 order. Empty for a type no operation reads,
     *                                 which the file says
     * @param  string  $naming  how the name was reached, for the file's findings
     * @param  list<string>  $findings  what the build worked out about the
     *                                  properties that the declared types do not
     *                                  show
     * @param  list<string>  $requests  the short names of the requests whose
     *                                  `dto()` returns this type, parallel to
     *                                  `$readers`
     */
    public function __construct(
        public string $shortName,
        public bool $partial,
        public string $position,
        public array $properties,
        public array $readers,
        public string $naming,
        public array $findings,
        public array $requests = [],
    ) {}

    public function relativePath(): string
    {
        return 'Data/'.$this->shortName.'.php';
    }
}
