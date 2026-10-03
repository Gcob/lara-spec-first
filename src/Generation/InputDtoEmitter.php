<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Turns one planned input DTO into the PHP of its class.
 *
 * **A `final readonly` class with a promoted constructor, a `from()` and a
 * `toArray()`, and nothing else**, written in full by the build. There is no
 * runtime engine to discover what the class is: the build read the schema, so
 * the cast, the key and the nested `from()` are plain lines of PHP and the class
 * explains itself to anyone who opens it.
 *
 * **Extends nothing, and depends on nothing but `Optional`.** `Arrayable` and
 * `JsonSerializable` are interfaces, so a DTO returned from a controller becomes
 * a JSON response through Laravel's own router with nothing registered, and the
 * one class of this package a generated DTO imports is `Optional`.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */
final readonly class InputDtoEmitter
{
    private const OPTIONAL_CLASS = 'Gcob\\LaraSpecFirst\\Data\\Optional';

    public function __construct(
        private string $namespace,
        private string $specPath,
    ) {}

    public function emit(PlannedInputDto $dto): GeneratedFile
    {
        $properties = $dto->properties;

        return new GeneratedFile(
            $dto->relativePath(),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace}\\Data;

            {$this->useStatements($dto)}

            {$this->docblock($dto)}
            final readonly class {$dto->shortName} implements Arrayable, JsonSerializable
            {
            {$this->constructor($properties)}

                /**
                 * @param  array<string, mixed>  \$payload
                 */
                public static function from(array \$payload): self
                {
                    return new self({$this->arguments($properties)});
                }

                /**
                 * @return array<string, mixed>
                 */
                public function toArray(): array
                {
            {$this->toArrayBody($properties)}
                }

                /**
                 * @return array<string, mixed>
                 */
                public function jsonSerialize(): array
                {
                    return \$this->toArray();
                }
            }

            PHP,
        );
    }

    /**
     * The import block: the two interfaces always, and the rest only when a
     * property uses it. An unused import is something a consumer's formatter
     * removes, and a formatter with something to remove is a formatter fighting
     * the next build.
     */
    private function useStatements(PlannedInputDto $dto): string
    {
        $imports = ['Illuminate\\Contracts\\Support\\Arrayable', 'JsonSerializable'];

        foreach ($dto->properties as $property) {
            array_push($imports, ...$property->type->imports());

            if ($property->optional) {
                $imports[] = self::OPTIONAL_CLASS;
            }
        }

        // Ordered the way php-cs-fixer's `ordered_imports` orders them, for the
        // reason ControllerEmitter gives at length.
        $imports = array_values(array_unique($imports));
        usort($imports, static fn (string $first, string $second): int => strcasecmp(
            str_replace('\\', ' ', $first),
            str_replace('\\', ' ', $second),
        ));

        return implode("\n", array_map(static fn (string $class): string => 'use '.$class.';', $imports));
    }

    /**
     * The promoted constructor, with the docblock for the properties whose
     * declared type says less than the schema did.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    private function constructor(array $properties): string
    {
        $docs = [];

        foreach ($properties as $property) {
            $doc = $this->doc($property);

            if ($doc !== null) {
                $docs[] = sprintf('     * @param  %s  $%s', $doc, $property->name);
            }
        }

        $block = $docs === [] ? '' : "    /**\n".implode("\n", $docs)."\n     */\n";

        if ($properties === []) {
            return $block.'    public function __construct() {}';
        }

        $lines = array_map(
            fn (InputDtoProperty $property): string => sprintf(
                '        public %s $%s,',
                $this->declared($property),
                $property->name,
            ),
            $properties,
        );

        return $block."    public function __construct(\n".implode("\n", $lines)."\n    ) {}";
    }

    /**
     * A property's type as PHP declares it.
     *
     * `mixed` is its own union and refuses to be in one, so an optional `mixed`
     * is declared `mixed` and says `Optional|mixed` in its docblock.
     */
    private function declared(InputDtoProperty $property): string
    {
        $native = $property->type->native();

        if ($native === InputDtoType::MIXED) {
            return $native;
        }

        if (! $property->optional) {
            return ($property->type->nullable ? '?' : '').$native;
        }

        return 'Optional|'.$native.($property->type->nullable ? '|null' : '');
    }

    /**
     * What the docblock says about a property, or null when the declared type
     * already says all of it.
     */
    private function doc(InputDtoProperty $property): ?string
    {
        $type = $property->type;
        $doc = $type->doc();

        if ($doc === null && ! ($property->optional && $type->native() === InputDtoType::MIXED)) {
            return null;
        }

        return ($property->optional ? 'Optional|' : '')
            .($doc ?? $type->native())
            .($type->nullable && $type->native() !== InputDtoType::MIXED ? '|null' : '');
    }

    /**
     * The named arguments `from()` hands the constructor.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    private function arguments(array $properties): string
    {
        if ($properties === []) {
            return '';
        }

        $lines = array_map(function (InputDtoProperty $property): string {
            $value = '$payload['.var_export($property->key, true).']';
            $built = $property->type->from($value);

            if ($property->optional) {
                $built = sprintf(
                    'array_key_exists(%s, $payload) ? %s : new Optional',
                    var_export($property->key, true),
                    str_contains($built, ' ? ') ? '('.$built.')' : $built,
                );
            }

            return sprintf('            %s: %s,', $property->name, $built);
        }, $properties);

        return "\n".implode("\n", $lines)."\n        ";
    }

    /**
     * `toArray()`: the contract's keys back out, leaving out every `Optional`.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    private function toArrayBody(array $properties): string
    {
        $always = array_filter($properties, static fn (InputDtoProperty $property): bool => ! $property->optional);
        $lines = [];

        if ($always === []) {
            $lines[] = '        $array = [];';
        } else {
            $lines[] = '        $array = [';

            foreach ($always as $property) {
                $lines[] = sprintf(
                    '            %s => %s,',
                    var_export($property->key, true),
                    $property->type->to('$this->'.$property->name),
                );
            }

            $lines[] = '        ];';
        }

        foreach ($properties as $property) {
            if (! $property->optional) {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('        if (! $this->%s instanceof Optional) {', $property->name);
            $lines[] = sprintf(
                '            $array[%s] = %s;',
                var_export($property->key, true),
                $property->type->to('$this->'.$property->name),
            );
            $lines[] = '        }';
        }

        $lines[] = '';
        $lines[] = '        return $array;';

        return implode("\n", $lines);
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
     * Where to go from here: the type's own interface, then each request whose
     * `dto()` builds it.
     *
     * **Backticks, and they are load-bearing**, for the reason the request's own
     * navigation gives: Pint rewrites a bare fully-qualified name in a docblock
     * into an import, and an import of a class this file never mentions in code
     * is one the build and a formatter would rewrite against each other.
     *
     * @return non-empty-list<string>
     */
    private function navigation(PlannedInputDto $dto): array
    {
        $lines = ['@implements Arrayable<string, mixed>'];

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
                .'ones the client did not send. A `PATCH` reads it, and so does a body that may be '
                .'absent entirely.';
        }

        $findings[] = $dto->readers === []
            ? ($dto->partial
                ? 'No operation reads this type. It is generated beside the full one for every body, so '
                    .'that adding or removing a `required` property never creates or deletes a name '
                    .'somebody has already imported.'
                : 'No operation reads this type directly: it is the type of a property of another DTO, or '
                    .'the full type of a body that only a `PATCH` or an optional body sends.')
            : 'Read by '.implode(', ', array_map(
                static fn (string $label): string => '`'.CommentText::safe($label).'`',
                $dto->readers,
            )).'.';

        return [...$findings, ...$dto->findings];
    }
}
