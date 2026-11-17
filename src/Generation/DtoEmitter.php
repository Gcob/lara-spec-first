<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What the two DTO emitters share: the constructor, `from()`'s arguments and `toArray()`'s body.
 *
 * **One writer for both directions, so a property reads the same in both files.** The key derivation, the `Optional`
 * check, the docblock of a type PHP declares as less than the schema said and the whole-payload property are written
 * here once. What a direction does differently is passed in, never branched on by a caller: the {@see DtoMode} says how
 * a value converts, and `defaultOf()` says whether a property carries a default.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */
abstract readonly class DtoEmitter
{
    protected const OPTIONAL_CLASS = 'Gcob\\LaraSpecFirst\\Data\\Optional';

    public function __construct(
        protected string $namespace,
        protected string $specPath,
    ) {}

    /**
     * What follows a promoted property's name: nothing on the request side, where the client sends every property it
     * wants built.
     */
    protected function defaultOf(InputDtoProperty $property): string
    {
        return '';
    }

    /**
     * The interfaces every class of this direction implements, as imports. A request DTO is both: its `toArray()` is
     * what mass assignment reads. A response DTO is `JsonSerializable` only, see `ResponseDtoEmitter`.
     *
     * @return list<string>
     */
    protected function interfaceImports(): array
    {
        return ['Illuminate\\Contracts\\Support\\Arrayable', 'JsonSerializable'];
    }

    /**
     * The import block: the interfaces the class implements always, and the rest only when a property uses it. An
     * unused import is something a consumer's formatter removes, and a formatter with something to remove is a
     * formatter fighting the next build.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    protected function useStatements(array $properties): string
    {
        $imports = $this->interfaceImports();

        foreach ($properties as $property) {
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
     * The promoted constructor, with the docblock for the properties whose declared type says less than the schema did.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    protected function constructor(array $properties): string
    {
        $docs = [];

        foreach ($properties as $property) {
            $doc = $this->doc($property);

            if ($doc !== null) {
                $values = $property->type->allowedValues();
                $docs[] = sprintf('     * @param  %s  $%s', $doc, $property->name).($values === null ? '' : '  '.$values);
            }
        }

        $block = $docs === [] ? '' : "    /**\n".implode("\n", $docs)."\n     */\n";

        if ($properties === []) {
            return $block.'    public function __construct() {}';
        }

        $lines = array_map(
            fn (InputDtoProperty $property): string => sprintf(
                '        public %s $%s%s,',
                $this->declared($property),
                $property->name,
                $this->defaultOf($property),
            ),
            $properties,
        );

        return $block."    public function __construct(\n".implode("\n", $lines)."\n    ) {}";
    }

    /**
     * A property's type as PHP declares it.
     *
     * `mixed` is its own union and refuses to be in one, so an optional `mixed` is declared `mixed` and says
     * `Optional|mixed` in its docblock.
     */
    protected function declared(InputDtoProperty $property): string
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
     * What the docblock says about a property, or null when the declared type already says all of it.
     */
    protected function doc(InputDtoProperty $property): ?string
    {
        $type = $property->type;
        $doc = $type->doc();

        if ($doc === null && $type->allowedValues() !== null) {
            $doc = $type->native();
        }

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
    protected function arguments(array $properties, DtoMode $mode = DtoMode::Request): string
    {
        if ($properties === []) {
            return '';
        }

        $lines = array_map(function (InputDtoProperty $property) use ($mode): string {
            $value = $property->wholePayload ? '$payload' : '$payload['.var_export($property->key, true).']';
            $built = $property->type->from($value, $mode);

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
    protected function toArrayBody(array $properties, DtoMode $mode = DtoMode::Request): string
    {
        // The keys of a map or open-object body are the data: the property is the array itself.
        if (self::carriesWholePayload($properties)) {
            return '        return '.$properties[0]->type->to('$this->'.$properties[0]->name, $mode).';';
        }

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
                    $property->type->to('$this->'.$property->name, $mode),
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
                $property->type->to('$this->'.$property->name, $mode),
            );
            $lines[] = '        }';
        }

        $lines[] = '';
        $lines[] = $mode === DtoMode::Json ? '        return (object) $array;' : '        return $array;';

        return implode("\n", $lines);
    }

    /**
     * Whether the type is the body's keys themselves (a map or an open object), which PHP types as `array-key` since it
     * turns a numeric key into an `int`.
     *
     * @param  list<InputDtoProperty>  $properties
     */
    protected static function carriesWholePayload(array $properties): bool
    {
        return $properties !== [] && $properties[0]->wholePayload;
    }
}
