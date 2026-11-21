<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Turns one planned input DTO into the PHP of its class.
 *
 * **A `final readonly` class with a promoted constructor, a `from()` and a `toArray()`, and nothing else**, written in
 * full by the build. There is no runtime engine to discover what the class is: the build read the schema, so the cast,
 * the key and the nested `from()` are plain lines of PHP and the class explains itself to anyone who opens it.
 *
 * **Extends nothing, and depends on nothing but `Optional`.** `Arrayable` and `JsonSerializable` are interfaces, so a
 * DTO returned from a controller becomes a JSON response through Laravel's own router with nothing registered, and the
 * one class of this package a generated DTO imports is `Optional`.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */
final readonly class InputDtoEmitter extends DtoEmitter
{
    public function emit(PlannedInputDto $dto): GeneratedFile
    {
        $properties = $dto->properties;
        $keys = self::carriesWholePayload($properties) ? 'array-key' : 'string';

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
                 * @param  array<{$keys}, mixed>  \$payload
                 */
                public static function from(array \$payload): self
                {
                    return new self({$this->arguments($properties)});
                }

                /**
                 * @return array<{$keys}, mixed>
                 */
                public function toArray(): array
                {
            {$this->toArrayBody($properties)}
                }

                /**
                 * @return array<{$keys}, mixed>
                 */
                public function jsonSerialize(): array
                {
                    return \$this->toArray();
                }
            }

            PHP,
        );
    }

    private function docblock(PlannedInputDto $dto): string
    {
        return GeneratedDocblock::render(
            [CommentText::safe($this->specPath), CommentText::safe($dto->position)],
            $this->findings($dto),
            $this->navigation($dto),
        );
    }

    /**
     * Where to go from here: the type's own interface, then each request whose `dto()` builds it.
     *
     * **Backticks, and they are load-bearing**, for the reason the request's own navigation gives: Pint rewrites a bare
     * fully-qualified name in a docblock into an import, and an import of a class this file never mentions in code is
     * one the build and a formatter would rewrite against each other.
     *
     * @return non-empty-list<string>
     */
    private function navigation(PlannedInputDto $dto): array
    {
        $lines = ['@implements Arrayable<'.(self::carriesWholePayload($dto->properties) ? 'array-key' : 'string').', mixed>'];

        // Between two different annotations, which `phpdoc_separation` would
        // otherwise insert for us in a consumer's formatter.
        if ($dto->requests !== []) {
            $lines[] = '';
        }

        foreach ($dto->requests as $request) {
            $lines[] = sprintf(
                '@see `\\%s\\Requests\\%s::dto()`',
                CommentText::safe($this->namespace),
                CommentText::safe($request),
            );
            $lines[] = '     — builds this type from the validated payload';
        }

        return $lines;
    }

    /**
     * What the build knew and the reader cannot see.
     *
     * @return non-empty-list<string>
     */
    private function findings(PlannedInputDto $dto): array
    {
        $findings = [$dto->naming];

        if ($dto->partial) {
            $findings[] = 'The partial type: every property is `Optional`, and `toArray()` leaves out the '
                .'ones the client did not send. A partial update reads it, and so does a body that may be '
                .'absent entirely.';
        }

        $findings[] = $dto->readers === []
            ? ($dto->partial
                ? 'No operation reads this type. It is generated beside the full one for every body, so '
                    .'that adding or removing a `required` property never creates or deletes a name '
                    .'somebody has already imported.'
                : 'No operation reads this type directly: it is the type of a property of another DTO, or '
                    .'the full type of a body that only a partial update or an optional body sends.')
            : 'Read by '.implode(', ', array_map(
                static fn (string $label): string => '`'.CommentText::safe($label).'`',
                $dto->readers,
            )).'.';

        return [...$findings, ...$dto->findings];
    }
}
