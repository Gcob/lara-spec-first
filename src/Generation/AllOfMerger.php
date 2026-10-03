<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;

/**
 * `allOf`, folded into the one schema a rule set is built from.
 *
 * **A payload has to satisfy every branch, so the merge is an intersection**:
 * properties and required names accumulate, a bound takes the tighter of two,
 * a type list keeps what both allow. That is what the document says, and
 * nothing here chooses.
 *
 * **Where two branches cannot be said as one, the build refuses.** Two types
 * with nothing in common, two enumerations with no shared value, two formats
 * or two patterns — one rule set holds one of each, so emitting either would
 * enforce half the contract and drop the other half without a word. And one
 * case is refused for being JSON Schema's own trap rather than a
 * contradiction: a branch writing `additionalProperties: false` forbids every
 * property the *other* branches declare, which almost never is what its author
 * meant, and reading it either way would be guessing.
 *
 * Kept apart from {@see RuleSetBuilder} because it is a statement about the
 * contract rather than about Laravel, and because it recurses on its own.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class AllOfMerger
{
    /**
     * @param  string  $identity  the operation's label, for a refusal's message
     * @param  string  $where  the field the schema sits on, for the same
     *
     * @throws ConflictingInputException
     */
    public static function merge(Schema $schema, string $identity, string $where): Schema
    {
        if ($schema->allOf === []) {
            return $schema;
        }

        $merged = self::withoutAllOf($schema);

        foreach ($schema->allOf as $branch) {
            $merged = self::combine($merged, self::merge($branch, $identity, $where), $identity, $where);
        }

        return $merged;
    }

    /**
     * @throws ConflictingInputException
     */
    private static function combine(Schema $a, Schema $b, string $identity, string $where): Schema
    {
        $refuse = static fn (string $what): ConflictingInputException => ConflictingInputException::contradictoryAllOf(
            $identity,
            $where,
            $what,
        );

        self::assertNoClosedTrap($a, $b, $refuse);
        self::assertNoClosedTrap($b, $a, $refuse);

        $properties = $a->properties;

        foreach ($b->properties as $name => $property) {
            // Each side merged first: a property written in two branches may
            // carry an `allOf` of its own, and combining the two unmerged
            // would drop it, with every constraint inside it.
            $properties[$name] = isset($properties[$name])
                ? self::combine(
                    self::merge($properties[$name], $identity, $where.'.'.$name),
                    self::merge($property, $identity, $where.'.'.$name),
                    $identity,
                    $where.'.'.$name,
                )
                : $property;
        }

        $dependentRequired = $a->dependentRequired;

        foreach ($b->dependentRequired as $trigger => $names) {
            $dependentRequired[$trigger] = array_values(array_unique([...$dependentRequired[$trigger] ?? [], ...$names]));
        }

        return new Schema(
            name: $a->name ?? $b->name,
            source: $a->name !== null ? $a->source : $b->source,
            types: self::types($a->types, $b->types, $refuse),
            format: self::same($a->format, $b->format, '`format`', $refuse),
            properties: $properties,
            required: array_values(array_unique([...$a->required, ...$b->required])),
            dependentRequired: $dependentRequired,
            items: match (true) {
                $a->items !== null && $b->items !== null => self::combine(
                    self::merge($a->items, $identity, $where.'.*'),
                    self::merge($b->items, $identity, $where.'.*'),
                    $identity,
                    $where.'.*',
                ),
                default => $a->items ?? $b->items,
            },
            enum: self::enum($a->enum, $b->enum, $refuse),
            examples: [...$a->examples, ...$b->examples],
            additionalProperties: match (true) {
                $a->additionalProperties === false || $b->additionalProperties === false => false,
                default => $a->additionalProperties ?? $b->additionalProperties,
            },
            minLength: self::tighter($a->minLength, $b->minLength, self::larger(...)),
            maxLength: self::tighter($a->maxLength, $b->maxLength, self::smaller(...)),
            pattern: self::same($a->pattern, $b->pattern, '`pattern`', $refuse),
            minimum: self::tighter($a->minimum, $b->minimum, self::larger(...)),
            maximum: self::tighter($a->maximum, $b->maximum, self::smaller(...)),
            exclusiveMinimum: self::tighter($a->exclusiveMinimum, $b->exclusiveMinimum, self::larger(...)),
            exclusiveMaximum: self::tighter($a->exclusiveMaximum, $b->exclusiveMaximum, self::smaller(...)),
            multipleOf: self::same($a->multipleOf, $b->multipleOf, '`multipleOf`', $refuse),
            minItems: self::tighter($a->minItems, $b->minItems, self::larger(...)),
            maxItems: self::tighter($a->maxItems, $b->maxItems, self::smaller(...)),
            uniqueItems: $a->uniqueItems || $b->uniqueItems,
            readOnly: $a->readOnly || $b->readOnly,
            writeOnly: $a->writeOnly || $b->writeOnly,
            isFilePart: $a->isFilePart || $b->isFilePart,
            contentMediaType: self::same($a->contentMediaType, $b->contentMediaType, '`contentMediaType`', $refuse),
            recursesTo: $a->recursesTo ?? $b->recursesTo,
        );
    }

    /**
     * Refuse a closed branch beside a branch declaring properties it does not.
     *
     * @param  callable(string): ConflictingInputException  $refuse
     *
     * @throws ConflictingInputException
     */
    private static function assertNoClosedTrap(Schema $closed, Schema $other, callable $refuse): void
    {
        if ($closed->additionalProperties !== false) {
            return;
        }

        $foreign = array_diff(array_keys($other->properties), array_keys($closed->properties));

        if ($foreign !== []) {
            throw $refuse(sprintf(
                'one branch writes `additionalProperties: false` and another declares `%s`, which '
                    .'JSON Schema reads as forbidden by the first',
                implode('`, `', $foreign),
            ));
        }
    }

    /**
     * The types both branches allow. An empty list states nothing, so it
     * yields to the other branch rather than intersecting to nothing.
     *
     * @param  list<SchemaType>  $a
     * @param  list<SchemaType>  $b
     * @param  callable(string): ConflictingInputException  $refuse
     * @return list<SchemaType>
     *
     * @throws ConflictingInputException
     */
    private static function types(array $a, array $b, callable $refuse): array
    {
        if ($a === [] || $b === []) {
            return $a === [] ? $b : $a;
        }

        // An integer is a number, so `integer` beside `number` is the
        // integer, not a contradiction: each side is widened with the integer
        // its `number` already allows, and the widening is taken back where
        // both sides said `number`, so it is not written twice.
        $widen = static fn (array $types): array => in_array(SchemaType::Number, $types, true)
            ? [...$types, SchemaType::Integer]
            : $types;
        $wideB = $widen($b);
        $shared = [];

        foreach ($widen($a) as $type) {
            if (in_array($type, $wideB, true) && ! in_array($type, $shared, true)) {
                $shared[] = $type;
            }
        }

        $bothSaidInteger = in_array(SchemaType::Integer, $a, true) && in_array(SchemaType::Integer, $b, true);

        if (in_array(SchemaType::Number, $shared, true) && ! $bothSaidInteger) {
            $shared = array_values(array_filter($shared, static fn (SchemaType $type): bool => $type !== SchemaType::Integer));
        }

        if ($shared === []) {
            throw $refuse(sprintf(
                'one branch is %s and another is %s',
                implode(' or ', array_map(static fn (SchemaType $type): string => '`'.$type->value.'`', $a)),
                implode(' or ', array_map(static fn (SchemaType $type): string => '`'.$type->value.'`', $b)),
            ));
        }

        return $shared;
    }

    /**
     * @param  list<mixed>|null  $a
     * @param  list<mixed>|null  $b
     * @param  callable(string): ConflictingInputException  $refuse
     * @return list<mixed>|null
     *
     * @throws ConflictingInputException
     */
    private static function enum(?array $a, ?array $b, callable $refuse): ?array
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        $shared = array_values(array_filter($a, static fn (mixed $value): bool => in_array($value, $b, true)));

        if ($shared === []) {
            throw $refuse('their `enum` lists share no value');
        }

        return $shared;
    }

    /**
     * One value both branches agree on, or the refusal.
     *
     * @template T of string|float
     *
     * @param  T|null  $a
     * @param  T|null  $b
     * @param  callable(string): ConflictingInputException  $refuse
     * @return T|null
     *
     * @throws ConflictingInputException
     */
    private static function same(string|float|null $a, string|float|null $b, string $keyword, callable $refuse): string|float|null
    {
        if ($a !== null && $b !== null && $a !== $b) {
            throw $refuse(sprintf('one branch writes %s `%s` and another `%s`', $keyword, $a, $b));
        }

        return $a ?? $b;
    }

    /**
     * @template T of int|float
     *
     * @param  T|null  $a
     * @param  T|null  $b
     * @param  callable(int|float, int|float): (int|float)  $pick
     * @return T|null
     */
    private static function tighter(int|float|null $a, int|float|null $b, callable $pick): int|float|null
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        /** @var T $picked one of the two, so of their type */
        $picked = $pick($a, $b);

        return $picked;
    }

    private static function larger(int|float $a, int|float $b): int|float
    {
        return $a > $b ? $a : $b;
    }

    private static function smaller(int|float $a, int|float $b): int|float
    {
        return $a < $b ? $a : $b;
    }

    private static function withoutAllOf(Schema $schema): Schema
    {
        return new Schema(
            name: $schema->name,
            types: $schema->types,
            format: $schema->format,
            properties: $schema->properties,
            required: $schema->required,
            dependentRequired: $schema->dependentRequired,
            items: $schema->items,
            enum: $schema->enum,
            examples: $schema->examples,
            additionalProperties: $schema->additionalProperties,
            minLength: $schema->minLength,
            maxLength: $schema->maxLength,
            pattern: $schema->pattern,
            minimum: $schema->minimum,
            maximum: $schema->maximum,
            exclusiveMinimum: $schema->exclusiveMinimum,
            exclusiveMaximum: $schema->exclusiveMaximum,
            multipleOf: $schema->multipleOf,
            minItems: $schema->minItems,
            maxItems: $schema->maxItems,
            uniqueItems: $schema->uniqueItems,
            readOnly: $schema->readOnly,
            writeOnly: $schema->writeOnly,
            isFilePart: $schema->isFilePart,
            contentMediaType: $schema->contentMediaType,
            recursesTo: $schema->recursesTo,
            source: $schema->source,
        );
    }
}
