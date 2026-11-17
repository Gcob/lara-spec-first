<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Support\DtoShape;
use Illuminate\Support\Str;

/**
 * What the two DTO planners share: the type a schema becomes, and the name it is given.
 *
 * **One mapping for both directions, so a schema is the same type whichever way it travels.** An object with properties
 * is a DTO of its own, a list is `list<T>`, an object without properties is a map, a named recursion is the class it
 * points back at. What differs is what each direction does around that mapping: the input planner decides which
 * properties a request cannot promise and builds two types per body, and the response planner mirrors the schema as
 * written. Each keeps its own `register()`, and this class never branches on which one it is: it asks.
 *
 * @internal Not public API.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
abstract class DtoPlanner
{
    /**
     * @var array<string, array{partial: bool, named: bool, multipart: bool, schema: Schema, position: string, base: string, origin: string, naming: string, properties: list<InputDtoProperty>, findings: list<string>}>
     */
    protected array $registry = [];

    /**
     * The definitions of the named schemas the walk is inside, the way the rules keep them: what a recursion marker is
     * resolved against, so a class is found for it whichever operation reached the schema first.
     *
     * @var list<Schema>
     */
    protected array $ancestors = [];

    protected string $identity = '';

    /**
     * What a class name ends with: `InputDto` on the request side, `Dto` on the response side.
     */
    abstract protected function suffix(): string;

    /**
     * Whether a schema is a file part, which only a request can carry.
     */
    abstract protected function isFile(Schema $schema): bool;

    /**
     * Whether a recursion marker is built into its ancestor's class, rather than left `mixed`.
     *
     * The request builds it only down to the depth it validated; a response mirrors what was written.
     */
    abstract protected function unrolls(Schema $site, ?Schema $ancestor): bool;

    /**
     * What a finding adds to say a `mixed` property is the same on the other side of the contract, or nothing.
     */
    abstract protected function mixedNote(): string;

    /**
     * The finding for a recursion marker that is not built into a class: it has no ancestor to be a class of, or it is
     * two markers at once.
     */
    abstract protected function unbuiltRecursionFinding(string $segment, string $position): string;

    /**
     * The finding for a recursion marker with properties written beside it, which its ancestor's class does not carry.
     */
    abstract protected function propertiesBesideFinding(string $segment, string $ancestor): string;

    /**
     * The finding for a recursion marker whose ancestor is not an object with properties.
     */
    abstract protected function ancestorNotAnObjectFinding(string $segment, string $position): string;

    /**
     * Put a nested or recursive object's DTO in the registry, or recognize it as one already there.
     *
     * @return string the short name
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    abstract protected function registerNested(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string;

    /**
     * What one property of a schema becomes.
     *
     * @param  string  $base  the parent's name without its `InputDto`, which an inline nested object takes its own name
     *                        from
     * @param  string  $segment  the property the schema is the value of
     * @param  string  $owner  the DTO the property belongs to, for its findings
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function type(Schema $written, string $base, string $segment, string $pointer, string $owner): InputDtoType
    {
        $schema = AllOfMerger::merge($written, $this->identity, '`'.$segment.'`');

        if ($schema->recursesTo !== null) {
            return $this->recursion($schema, $segment, $owner);
        }

        $before = count($this->ancestors);
        // What is above the site, taken before its own definitions go on the stack: a schema that only recurses into
        // itself is not above the field that extends it.
        $above = array_values(array_filter(array_map(static fn (Schema $schema): ?string => $schema->source, $this->ancestors)));
        array_push($this->ancestors, ...RuleSetBuilder::definitions($written, $schema, $this->identity, '`'.$segment.'`'));

        try {
            return $this->typeOf($written, $schema, $base, $segment, $pointer, $owner, $above);
        } finally {
            $this->ancestors = array_slice($this->ancestors, 0, $before);
        }
    }

    /**
     * What one property becomes, once its own definitions are on the stack.
     *
     * @param  list<string>  $above  the positions of the named schemas above the property
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function typeOf(Schema $written, Schema $schema, string $base, string $segment, string $pointer, string $owner, array $above): InputDtoType
    {
        $enum = $schema->enum;
        $nullable = $schema->isNullable() || ($enum !== null && in_array(null, $enum, true));
        $values = $enum === null
            ? []
            : array_values(array_filter($enum, static fn (mixed $value): bool => $value !== null));
        $literals = array_values(array_filter($values, is_scalar(...)));
        $literals = count($literals) === count($values) ? $literals : [];
        $type = $schema->soleType();

        if ($this->isFile($schema)) {
            return new InputDtoType(InputDtoType::FILE, $nullable);
        }

        switch ($type) {
            case SchemaType::String:
                if ($literals === [] && $schema->format === 'date-time') {
                    return new InputDtoType(InputDtoType::DATE_TIME, $nullable);
                }

                if ($literals === [] && $schema->format === 'date') {
                    return new InputDtoType(InputDtoType::DATE, $nullable);
                }

                return new InputDtoType(InputDtoType::STRING, $nullable, literals: $literals);
                // The three that are cast keep their values but not a literal union:
                // `(int) $payload['x']` is an `int` to Larastan, never `1|2`, so the
                // union would be a claim the code beside it contradicts. The values
                // are written in the `@param` description instead.
            case SchemaType::Integer:
                return new InputDtoType(InputDtoType::INT, $nullable, literals: $literals);
            case SchemaType::Number:
                return new InputDtoType(InputDtoType::FLOAT, $nullable, literals: $literals);
            case SchemaType::Boolean:
                return new InputDtoType(InputDtoType::BOOL, $nullable, literals: $literals);
            case SchemaType::Array:
                $item = $schema->items === null
                    ? new InputDtoType(InputDtoType::MIXED)
                    : $this->type($schema->items, $base, $segment.'Item', self::within($pointer, '/items'), $owner);

                return new InputDtoType(InputDtoType::LIST, $nullable, item: $item);
            case SchemaType::Object:
                return $schema->properties === []
                    ? $this->map($schema, $base, $segment, $pointer, $nullable, $owner)
                    : $this->nested($written, $schema, $base, $segment, $pointer, $nullable, $owner, $above);
        }

        // No single type: an untyped enumeration of one kind of value says
        // what it is, and anything else is `mixed`, which the request's own
        // findings already say it did not type either.
        $kind = $values === [] ? null : self::enumKind($values);

        if ($kind !== null && $type === null && $schema->types === []) {
            return new InputDtoType($kind, $nullable, literals: $literals);
        }

        $this->registry[$owner]['findings'][] = $this->noSingleTypeFinding($segment, $schema);

        return new InputDtoType(InputDtoType::MIXED);
    }

    /**
     * An object with no properties: the keys it arrives with, each value typed by the schema the request judged it by.
     *
     * **The value schema the request judges is the one typed**, as a list's element is, through the same reading of the
     * schema ({@see RuleSetBuilder::mapValueSchema()}). One that states nothing is an open object, as in the rules: the
     * values stay `mixed`. The value is named after the property with `Value`, where a list's element says `Item`.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function map(Schema $schema, string $base, string $segment, string $pointer, bool $nullable, string $owner): InputDtoType
    {
        $value = RuleSetBuilder::mapValueSchema($schema);

        if ($value === null || RuleSetBuilder::allowsAnyValue($value)) {
            return new InputDtoType(InputDtoType::MAP, $nullable);
        }

        return new InputDtoType(
            InputDtoType::MAP,
            $nullable,
            item: $this->type($value, $base, $segment.'Value', self::within($pointer, '/additionalProperties'), $owner),
        );
    }

    /**
     * What the file says about a property that states no single type. One wording for a schema reached directly and one
     * reached through a recursion marker, so the bytes of a class do not depend on which the build met first.
     */
    protected function noSingleTypeFinding(string $segment, Schema $schema): string
    {
        $stated = array_filter($schema->types, static fn (SchemaType $stated): bool => $stated !== SchemaType::Null);

        return sprintf(
            '`%s` states %s, so it is `mixed`.'.$this->mixedNote(),
            $segment,
            match (count($stated)) {
                0 => $schema->types === [] ? 'no type' : 'only `null`',
                default => 'more than one type',
            },
        );
    }

    /**
     * An object with properties, as a DTO of its own.
     *
     * @param  list<string>  $above  the positions of the named schemas above the property
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function nested(Schema $written, Schema $merged, string $base, string $segment, string $pointer, bool $nullable, string $owner, array $above): InputDtoType
    {
        $recursive = $this->recursiveBranch($written, $segment, $above);

        if ($recursive !== null) {
            $type = $this->ancestorType($recursive[0], $recursive[1], $segment, $owner);

            return $type->kind === InputDtoType::DTO ? new InputDtoType(InputDtoType::DTO, $nullable || $type->nullable, class: $type->class) : $type;
        }

        [$name, $source] = $this->nameOf($written, $merged);
        $position = $source ?? $pointer;

        if ($name === null) {
            $ownBase = $base.self::studly($segment);
            $origin = sprintf('derived from its parent "%s" and the property "%s"', $base, $segment);
            $naming = sprintf(
                'Class name derived from its parent\'s name and the property `%s`, because the object is '
                    .'written inline and has no name of its own.',
                $segment,
            );
        } else {
            $ownBase = $this->className($name, $position);
            $origin = 'the schema\'s name';
            $naming = self::namedAfter($name, $position);
        }

        $class = $this->registerNested($ownBase.$this->suffix(), $written, $merged, $position, $ownBase, $origin, $naming, $name !== null);

        return new InputDtoType(InputDtoType::DTO, $nullable, class: $class);
    }

    /**
     * The name the author gave a schema, and where it is written.
     *
     * A 3.0 `$ref` with a sibling keyword is written as an `allOf` of one branch, so the name lives on the branch:
     * reading only the wrapper would name that DTO after its property and give the same schema two classes. More than
     * one branch is a composition and has no single name.
     *
     * **Only when the wrapper adds no shape of its own.** An `example`, a `description` or a `readOnly` beside the
     * `$ref` change nothing a DTO holds. A `required`, a `properties` or an `additionalProperties` do: the wrapper is
     * then a different schema from the one it points at, and sharing the branch's class by position would give one
     * class two shapes.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function nameOf(Schema $written, Schema $merged): array
    {
        if ($written->name !== null) {
            return [$written->name, $written->source];
        }

        return count($written->allOf) === 1 && $merged->name !== null && ! DtoShape::isAddedBy($written)
            ? [$merged->name, $merged->source]
            : [null, null];
    }

    /**
     * A schema's name as a class name stem.
     *
     * @throws UnusableNameException
     */
    protected function className(string $schemaName, string $position): string
    {
        // Not an identifier character is dropped, so `MyApp.Models.NewUser` is
        // `MyAppModelsNewUser`. Two names that derive one class are refused when
        // they meet, as two different schemas under one name are.
        $studly = (string) preg_replace('/[^A-Za-z0-9_]/', '', Str::studly($schemaName));

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $studly) !== 1) {
            throw UnusableNameException::schemaNameUnusable($schemaName, $position);
        }

        return $studly;
    }

    /**
     * A recursion marker: the class of the schema it points back at, wherever the walk came in.
     *
     * **The request validates down to the depth the schema states and refuses what is below it**, so a class built from
     * a marker is built from input that was checked. The class is the ancestor's own definition, found on the stack the
     * way the rules find it, and registered here when nothing walked that schema as a schema of its own: a body written
     * `allOf: [Category, {required: [name]}]` is a class of its own, and `Category` is only reached through the marker.
     *
     * What the request did not unroll stays `mixed` ({@see RuleSetBuilder::unrolls()}), asked of the ancestor this
     * planner holds on its own stack. So does a marker with properties written beside it, which the request does
     * validate: that is a schema the ancestor's class does not say.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function recursion(Schema $site, string $segment, string $owner): InputDtoType
    {
        $position = (string) $site->recursesTo;
        $ancestor = null;

        foreach (array_reverse($this->ancestors) as $definition) {
            if ($definition->source === $position) {
                $ancestor = $definition;

                break;
            }
        }

        if ($ancestor === null || ! $this->unrolls($site, $ancestor)) {
            $this->registry[$owner]['findings'][] = $this->unbuiltRecursionFinding($segment, $position);

            return new InputDtoType(InputDtoType::MIXED);
        }

        return $this->ancestorType($ancestor, AllOfMerger::besideMarker($site), $segment, $owner);
    }

    /**
     * What a site is when it is the schema a recursion points back at, written alone or with something beside it: the
     * ancestor's own class, or `mixed`. One answer for a written site whichever way the build reaches it, as a marker
     * ({@see self::recursion()}) or as the expanded schema ({@see self::recursiveBranch()}).
     *
     * `mixed` where the class cannot say what the request validated. A `properties` beside the reference is validated
     * at every level, and the ancestor's class has no property for it. And a schema that is not an object with
     * properties, as the rules expand it, has no type rule or no keys to read: the class of an untyped component would
     * be built from a string the request let through, and one of an array would read no key of the list.
     *
     * @param  Schema  $ancestor  the definition of the schema the site points back at
     * @param  Schema|null  $beside  what the site writes beside the reference
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    protected function ancestorType(Schema $ancestor, ?Schema $beside, string $segment, string $owner): InputDtoType
    {
        $position = (string) $ancestor->source;

        if ($beside !== null && $beside->properties !== []) {
            $this->registry[$owner]['findings'][] = $this->propertiesBesideFinding($segment, (string) $ancestor->name);

            return new InputDtoType(InputDtoType::MIXED);
        }

        $expanded = $beside === null
            ? $ancestor
            : AllOfMerger::merge(new Schema(allOf: [$ancestor, $beside]), $this->identity, '`'.$segment.'`');

        if ($expanded->soleType() === null) {
            $this->registry[$owner]['findings'][] = $this->noSingleTypeFinding($segment, $expanded);

            return new InputDtoType(InputDtoType::MIXED);
        }

        if ($expanded->soleType() !== SchemaType::Object || $expanded->properties === []) {
            $this->registry[$owner]['findings'][] = $this->ancestorNotAnObjectFinding($segment, $position);

            return new InputDtoType(InputDtoType::MIXED);
        }

        $name = (string) $ancestor->name;
        $base = $this->className($name, $position);
        $class = $this->registerNested(
            $base.$this->suffix(),
            $ancestor,
            $ancestor,
            $position,
            $base,
            'the schema\'s name',
            self::namedAfter($name, $position),
            true,
        );
        // The cut lets `null` through when the ancestor allows it, and the levels above it when what is expanded does.
        $nullable = self::allowsNull($ancestor) || self::allowsNull($expanded);

        return new InputDtoType(InputDtoType::DTO, $nullable, class: $class);
    }

    protected static function allowsNull(Schema $schema): bool
    {
        return $schema->isNullable() || ($schema->enum !== null && in_array(null, $schema->enum, true));
    }

    /**
     * The branch of an `allOf` that is a schema in a recursive cycle through this site, and what the others write.
     *
     * A site such as `allOf: [Author, {required: [name]}]` in `Book` is a recursion marker when the build comes in
     * through `Author`, and the expanded `Author` when it comes in through `Book`. It has to be typed the same either
     * way, so the second path asks the question the first does: a branch is the ancestor when it is named and holds a
     * marker aimed at a schema the walk is inside *above the site*. Exactly one is, or the site is an ordinary
     * composition.
     *
     * @param  list<string>  $above  the positions of the named schemas above the site
     * @return array{0: Schema, 1: Schema|null}|null the branch's definition and what is written beside it
     *
     * @throws ConflictingInputException
     */
    protected function recursiveBranch(Schema $written, string $segment, array $above): ?array
    {
        $found = array_keys(array_filter(
            $written->allOf,
            static fn (Schema $branch): bool => $branch->source !== null && self::pointsBackInto($branch, $above),
        ));

        if (count($found) !== 1) {
            return null;
        }

        $label = '`'.$segment.'`';
        $others = $written->allOf;
        unset($others[$found[0]]);
        $rest = new Schema(...[...get_object_vars($written), 'allOf' => array_values($others), 'name' => null, 'source' => null, 'ignored' => []]);
        $beside = AllOfMerger::merge($rest, $this->identity, $label);

        return [AllOfMerger::merge($written->allOf[$found[0]], $this->identity, $label), $beside == new Schema ? null : $beside];
    }

    /**
     * Whether a recursion marker aimed at one of these positions is written anywhere in a schema.
     *
     * @param  list<string>  $positions
     */
    protected static function pointsBackInto(Schema $schema, array $positions): bool
    {
        if ($schema->recursesTo !== null) {
            return in_array($schema->recursesTo, $positions, true);
        }

        foreach ([
            ...array_values($schema->properties),
            ...$schema->allOf,
            ...($schema->items === null ? [] : [$schema->items]),
            ...($schema->additionalPropertiesSchema === null ? [] : [$schema->additionalPropertiesSchema]),
        ] as $child) {
            if (self::pointsBackInto($child, $positions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How a class named after a schema says so. One wording for the root and the nested use, so the class reads the
     * same whichever the walk reached first.
     */
    protected static function namedAfter(string $name, string $position): string
    {
        return sprintf(
            'Class name taken from the schema `%s`, written at `%s`. Every operation reaching that '
                .'schema shares this type.',
            $name,
            $position,
        );
    }

    /**
     * A position with one step more: a pointer takes it as it is, and a whole file, which has no `#` yet, gains one.
     */
    protected static function within(string $position, string $step): string
    {
        return str_contains($position, '#') ? $position.$step : $position.'#'.$step;
    }

    protected static function studly(string $segment): string
    {
        $studly = (string) preg_replace('/[^A-Za-z0-9_]/', '', Str::studly($segment));

        return $studly === '' ? 'Property' : $studly;
    }

    /**
     * The PHP property a contract key becomes: the key when it is an identifier, and a derived name when it is not.
     */
    protected static function propertyName(string $key): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $key);

        if ($name === '' || preg_match('/^[0-9]/', $name) === 1) {
            $name = '_'.$name;
        }

        // Legal in a schema and fatal as a parameter name.
        return $name === 'this' ? '_this' : $name;
    }

    /**
     * The one scalar type an untyped enumeration's values share, or null.
     *
     * @param  list<mixed>  $values
     */
    protected static function enumKind(array $values): ?string
    {
        $kinds = array_unique(array_map(get_debug_type(...), $values));
        sort($kinds);

        return match ($kinds) {
            ['string'] => InputDtoType::STRING,
            ['int'] => InputDtoType::INT,
            ['bool'] => InputDtoType::BOOL,
            ['float'], ['float', 'int'] => InputDtoType::FLOAT,
            default => null,
        };
    }
}
