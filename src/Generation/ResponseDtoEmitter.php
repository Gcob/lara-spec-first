<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Turns one planned response DTO into the PHP of its class.
 *
 * **The same class as an input DTO, written for the other direction.** A `final readonly` class with a promoted
 * constructor, a `from()`, a `toArray()` and a `jsonSerialize()`. Nothing is cast: a response is built from what the
 * application holds, so a wrong type is a `TypeError` naming the property. An optional property defaults to `Optional`,
 * so a response that leaves it out builds without saying so.
 *
 * **`toArray()` is a PHP array and `jsonSerialize()` is what the encoder is given**: nested DTOs reach it as
 * themselves, so each one writes a JSON object even when it has nothing to say, and a map is cast to one, so an empty
 * map is `{}` and a map keyed `0` and `1` is not a list.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */
final readonly class ResponseDtoEmitter extends DtoEmitter
{
    public function emit(PlannedResponseDto $dto): GeneratedFile
    {
        $properties = $dto->properties;

        return new GeneratedFile(
            $dto->relativePath(),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace}\\Data;

            {$this->useStatements($properties)}

            {$this->docblock($dto)}
            final readonly class {$dto->shortName} implements Arrayable, JsonSerializable
            {
            {$this->constructor($properties)}

                /**
                 * @param  array<string, mixed>  \$payload
                 */
                public static function from(array \$payload): self
                {
                    return new self({$this->arguments($properties, DtoMode::Response)});
                }

                /**
                 * @return array<string, mixed>
                 */
                public function toArray(): array
                {
            {$this->toArrayBody($properties, DtoMode::Response)}
                }

                public function jsonSerialize(): object
                {
            {$this->jsonBody($properties)}
                }
            }

            PHP,
        );
    }

    /**
     * The body of `jsonSerialize()`: the same array as `toArray()` as an object, which is all it is when no property
     * holds a DTO or a map, and the lines of its own when one does.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    private function jsonBody(array $properties): string
    {
        foreach ($properties as $property) {
            if ($property->type->to('$value', DtoMode::Response) !== $property->type->to('$value', DtoMode::Json)) {
                return $this->toArrayBody($properties, DtoMode::Json);
            }
        }

        return '        return (object) $this->toArray();';
    }

    protected function defaultOf(InputDtoProperty $property): string
    {
        // Without parentheses, as `from()` writes it: the formatter takes them off, and a build and a formatter that
        // disagree rewrite each other forever.
        return $property->optional ? ' = new Optional' : '';
    }

    private function docblock(PlannedResponseDto $dto): string
    {
        return GeneratedDocblock::render(
            [CommentText::safe($this->specPath), CommentText::safe($dto->position)],
            $this->findings($dto),
            ['@implements Arrayable<string, mixed>'],
        );
    }

    /**
     * What the build knew and the reader cannot see.
     *
     * @return non-empty-list<string>
     */
    private function findings(PlannedResponseDto $dto): array
    {
        $readers = $dto->readers === []
            ? 'No operation answers with this type directly: it is the type of a property of another DTO.'
            : 'Read by '.implode(', ', array_map(
                static fn (array $reader): string => sprintf('`%s` (`%s`)', CommentText::safe($reader['label']), CommentText::safe($reader['status'])),
                $dto->readers,
            )).'.';

        return [$dto->naming, $readers, ...$dto->findings];
    }
}
