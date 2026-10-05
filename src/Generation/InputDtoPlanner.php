<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Illuminate\Support\Str;

/**
 * Works out every input DTO of a build: its name, its properties, and which
 * operation reads it.
 *
 * **A DTO is named after the schema it describes, never after the `$ref` that
 * reached it or the file that holds it.** `#/components/schemas/Pet` and
 * `./other.yaml#/components/schemas/Pet` are one `PetInputDto`, because splitting
 * a specification across files does not change the contract and must not rename
 * a class a custom controller imports. Two operations sending one `$ref` share
 * one type for the same reason. Two schemas that are *not* the same and give the
 * same name are refused, naming both positions.
 *
 * **The request side always carries `Input`**, `$ref` or not, so a schema used
 * both ways does not collide with its response DTO (#39).
 *
 * **Two types per body, always.** The full one and the `Partial`, even when they
 * are identical, because a class that exists only under a condition is a class a
 * developer has to check for before importing. Only the body's root has a
 * partial: a nested object inside a `PATCH` stays complete.
 *
 * **Built before it is written.** One planner per build, so that a refusal costs
 * nothing: no file exists yet when two schemas turn out to share a name.
 *
 * @internal Not public API.
 *
 * @see docs/guide/code-generation/request-validation.md — "Two directions, two types"
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
final class InputDtoPlanner
{
    /**
     * @var array<string, array{partial: bool, named: bool, multipart: bool, schema: Schema, position: string, naming: string, properties: list<InputDtoProperty>, findings: list<string>}>
     */
    private array $registry = [];

    /**
     * The operations that read each DTO, by DTO and in document order, and the
     * request each is read through.
     *
     * @var array<string, list<string>>
     */
    private array $readers = [];

    /**
     * @var array<string, list<string>>
     */
    private array $requests = [];

    /**
     * @var array<string, string>
     */
    private array $readBy = [];

    private string $identity = '';

    private bool $multipart = false;

    /**
     * @param  list<PlannedRequest>  $requests  in document order
     *
     * @throws UnusableNameException two schemas under one name, a name that is not a class
     *                               name, or two keys under one property name
     * @throws ConflictingInputException an `allOf` whose branches disagree
     */
    public function plan(array $requests): InputDtoPlan
    {
        foreach ($requests as $request) {
            $this->root($request);
        }

        $dtos = [];

        foreach ($this->registry as $shortName => $entry) {
            $dtos[] = new PlannedInputDto(
                $shortName,
                $entry['partial'],
                $entry['position'],
                $entry['properties'],
                $this->readers[$shortName] ?? [],
                $entry['naming'],
                $entry['findings'],
                $this->requests[$shortName] ?? [],
            );
        }

        return new InputDtoPlan($dtos, $this->readBy);
    }

    /**
     * The two types of one operation's body, and the one its `dto()` reads.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function root(PlannedRequest $request): void
    {
        $written = $request->rules->body;

        if ($written === null) {
            return;
        }

        $operation = $request->operation;
        $body = $operation->requestBody;
        assert($body !== null);

        $this->identity = $operation->label();
        $this->multipart = $request->rules->multipart;

        $merged = AllOfMerger::merge($written, $this->identity, 'the body');

        // A body that is not an object has no rule set and no DTO, for the same
        // reason: a property is a field name, and a bare value has none.
        if ($merged->soleType() !== SchemaType::Object) {
            return;
        }

        $mediaTypes = array_values(array_intersect($body->mediaTypes(), RuleSetBuilder::READ_MEDIA_TYPES));
        $inline = DocumentPointer::forOperation($operation).'/requestBody/content/'
            .DocumentPointer::escape($mediaTypes[0] ?? '').'/schema';

        // Where the schema is written: its component when it has one, so that an
        // inline object nested in it reports a position inside the component and
        // not inside whichever operation happened to reach it first.
        [$name, $source] = $this->nameOf($written, $merged);
        $position = $source ?? $inline;

        if ($name === null) {
            $base = $request->name->stem();
            $naming = sprintf(
                'Class name derived from the operation\'s request, `%s`, because the body is written '
                    .'inline and has no name of its own.',
                $request->name->shortName,
            );
        } else {
            $base = $this->className($name, $position);
            $naming = sprintf(
                'Class name taken from the schema `%s`, written at `%s`. Every operation sending that '
                    .'schema shares this type.',
                $name,
                $position,
            );
        }

        $named = $name !== null;
        $full = $this->register($base.'InputDto', false, $written, $merged, $position, $base, $naming, $named);
        $partial = $this->register($base.'PartialInputDto', true, $written, $merged, $position, $base, $naming, $named);

        // The `PATCH` reads the partial type, and so does a body that may be
        // absent entirely: there every property may be missing.
        $reads = $operation->method === HttpMethod::Patch || ! $body->required ? $partial : $full;

        $this->readers[$reads][] = $this->identity;
        $this->requests[$reads][] = $request->name->shortName;
        $this->readBy[$this->identity] = $reads;
    }

    /**
     * Put one DTO in the registry and build its properties, or recognize it as
     * one already there.
     *
     * Registered *before* its properties are built, so a node that recurses
     * back to it finds it instead of building it again.
     *
     * @return string the short name
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function register(
        string $shortName,
        bool $partial,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $naming,
        bool $named,
    ): string {
        if (isset($this->registry[$shortName])) {
            $existing = $this->registry[$shortName];

            // A named schema is identified by where it is written, and an inline one
            // by what it says. By position because the merged value depends on how
            // deep the walk expanded it and on what a 3.0 `$ref` wrapper carries
            // beside it (an `example`, a `readOnly`), so one component reached two
            // ways would be refused as two. By value, serialized rather than loosely
            // compared, because `[0]` and `[false]` are `==`.
            $same = $named && $existing['named']
                ? $existing['position'] === $position
                : ! $named && ! $existing['named'] && serialize($existing['schema']) === serialize($merged);

            if ($existing['partial'] !== $partial || ! $same) {
                throw UnusableNameException::inputDtoClaimedTwice($shortName, $existing['position'], $position);
            }

            // One schema, two bodies: a part that is a file in a multipart body
            // is a string in any other, so a schema with a file part in it
            // needs a type for each and has only the one name.
            if ($existing['multipart'] !== $this->multipart && self::hasFilePart($merged)) {
                throw UnusableNameException::inputDtoServesFilesAndStrings($shortName, $position);
            }

            return $shortName;
        }

        $this->registry[$shortName] = [
            'partial' => $partial,
            'named' => $named,
            'multipart' => $this->multipart,
            'schema' => $merged,
            'position' => $position,
            'naming' => $naming,
            'properties' => [],
            'findings' => [],
        ];

        $properties = [];
        $claimed = [];

        foreach ($merged->properties as $key => $property) {
            $key = (string) $key;

            // Laravel reads a `*` in a rule key as a wildcard and offers no
            // escape, so the rule set emits nothing for such a key and the
            // validated payload never carries it. A required property reading
            // `$payload[$key]` would then fail on every request.
            if (RuleSetBuilder::unescapableKey($key) !== null) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` carries a `*`, which the request cannot validate, so it never reaches the '
                        .'payload and this type has no property for it.',
                    $key,
                );

                continue;
            }

            $name = self::propertyName($key);

            if (isset($claimed[$name])) {
                throw UnusableNameException::propertyClaimedTwice($shortName, $name, $claimed[$name], $key);
            }

            $claimed[$name] = $key;

            if ($name !== $key) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is not a PHP identifier, so it is read into `$%s`. `from()` and `toArray()` keep the key as written.',
                    $key,
                    $name,
                );
            }

            $type = $this->type(
                $property,
                $base,
                $key,
                self::within($position, '/properties/'.DocumentPointer::escape($key)),
                $shortName,
            );

            // A required key Laravel cannot name in `required_array_keys` is not
            // enforced when its object is nested, so a request can reach `from()`
            // without it. The DTO is shared by every operation that reaches the
            // schema, whether as a body or inside another, so its shape cannot
            // depend on which came first: the key is optional in both.
            $optional = $partial
                || ! in_array($key, $merged->required, true)
                || RuleSetBuilder::breaksKeyList($key);

            if (! $partial && $optional && in_array($key, $merged->required, true)) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is required by the schema, but its name has a `.` or a `,`, which Laravel cannot '
                        .'name in `required_array_keys` when the object is nested, so the property is `Optional` '
                        .'here whatever the rules say at the root.',
                    $key,
                );
            }

            $properties[] = new InputDtoProperty($key, $name, $type, $optional);
        }

        $this->registry[$shortName]['properties'] = $properties;

        return $shortName;
    }

    /**
     * What one property of a schema becomes.
     *
     * @param  string  $base  the parent's name without its `InputDto`, which an
     *                        inline nested object takes its own name from
     * @param  string  $segment  the property the schema is the value of
     * @param  string  $owner  the DTO the property belongs to, for its findings
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function type(Schema $written, string $base, string $segment, string $pointer, string $owner): InputDtoType
    {
        $schema = AllOfMerger::merge($written, $this->identity, '`'.$segment.'`');

        // A recursive node is the shape of its ancestor, and no rule set reaches an
        // infinite depth, so the rules validate nothing below it. A DTO built from
        // it would be built from input nothing checked: a `parent: "oops"` would be
        // a `TypeError` in `from()` and a 500 where the request owed a 422. So it
        // is read as it arrived, `mixed`, and the file says why.
        if ($schema->recursesTo !== null) {
            $this->registry[$owner]['findings'][] = sprintf(
                '`%s` points back at `%s`, and the request validates nothing below that point, so it is '
                    .'`mixed` rather than built into a DTO from input nothing checked.',
                $segment,
                $schema->recursesTo,
            );

            return new InputDtoType(InputDtoType::MIXED);
        }

        $enum = $schema->enum;
        $nullable = $schema->isNullable() || ($enum !== null && in_array(null, $enum, true));
        $values = $enum === null
            ? []
            : array_values(array_filter($enum, static fn (mixed $value): bool => $value !== null));
        $literals = array_values(array_filter($values, is_scalar(...)));
        $literals = count($literals) === count($values) ? $literals : [];
        $type = $schema->soleType();

        if (RuleSetBuilder::isFile($schema, $this->multipart)) {
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
                    ? new InputDtoType(InputDtoType::MAP, $nullable)
                    : $this->nested($written, $schema, $base, $segment, $pointer, $nullable, $owner);
        }

        // No single type: an untyped enumeration of one kind of value says
        // what it is, and anything else is `mixed`, which the request's own
        // findings already say it did not type either.
        $kind = $values === [] ? null : self::enumKind($values);

        if ($kind !== null && $type === null && $schema->types === []) {
            return new InputDtoType($kind, $nullable, literals: $literals);
        }

        $stated = array_filter($schema->types, static fn (SchemaType $stated): bool => $stated !== SchemaType::Null);

        $this->registry[$owner]['findings'][] = sprintf(
            '`%s` states %s, so it is `mixed`. The request\'s findings say the same about its rules.',
            $segment,
            match (count($stated)) {
                0 => $schema->types === [] ? 'no type' : 'only `null`',
                default => 'more than one type',
            },
        );

        return new InputDtoType(InputDtoType::MIXED);
    }

    /**
     * An object with properties, as a DTO of its own.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function nested(Schema $written, Schema $merged, string $base, string $segment, string $pointer, bool $nullable, string $owner): InputDtoType
    {
        [$name, $source] = $this->nameOf($written, $merged);
        $position = $source ?? $pointer;

        // A schema in a recursive pair is expanded to a different depth depending
        // on where the walk entered, so its shape, and the rules that validated it,
        // depend on the operation that reached it first. One class cannot be right
        // for both: the request that reached it from the other side would hand
        // `from()` input nothing checked. It is read as it arrived instead.
        if ($name !== null && self::dependsOnEntry($merged, $source)) {
            $this->registry[$owner]['findings'][] = sprintf(
                '`%s` is the schema `%s`, which refers back to a schema outside itself, so what the request '
                    .'validates below it depends on where the request starts. It is `mixed` rather than a DTO '
                    .'that would be right for one operation and wrong for another.',
                $segment,
                $name,
            );

            return new InputDtoType(InputDtoType::MIXED);
        }

        if ($name === null) {
            $ownBase = $base.self::studly($segment);
            $naming = sprintf(
                'Class name derived from its parent\'s name and the property `%s`, because the object is '
                    .'written inline and has no name of its own.',
                $segment,
            );
        } else {
            $ownBase = $this->className($name, $position);
            $naming = sprintf(
                'Class name taken from the schema `%s`, written at `%s`. Every operation reaching that '
                    .'schema shares this type.',
                $name,
                $position,
            );
        }

        $class = $this->register($ownBase.'InputDto', false, $written, $merged, $position, $ownBase, $naming, $name !== null);

        return new InputDtoType(InputDtoType::DTO, $nullable, class: $class);
    }

    /**
     * The name the author gave a schema, and where it is written.
     *
     * A 3.0 `$ref` with a sibling keyword is written as an `allOf` of one
     * branch, so the name lives on the branch: reading only the wrapper would
     * name that DTO after its property and give the same schema two classes.
     * More than one branch is a composition and has no single name.
     *
     * **Only when the wrapper adds no shape of its own.** An `example`, a
     * `description` or a `readOnly` beside the `$ref` change nothing a DTO
     * holds. A `required`, a `properties` or an `additionalProperties` do: the
     * wrapper is then a different schema from the one it points at, and sharing
     * the branch's class by position would give one class two shapes.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function nameOf(Schema $written, Schema $merged): array
    {
        if ($written->name !== null) {
            return [$written->name, $written->source];
        }

        $addsShape = $written->properties !== []
            || $written->required !== []
            || $written->dependentRequired !== []
            || $written->additionalProperties !== null
            || $written->types !== []
            || $written->items !== null
            || $written->enum !== null;

        return count($written->allOf) === 1 && $merged->name !== null && ! $addsShape
            ? [$merged->name, $merged->source]
            : [null, null];
    }

    /**
     * A schema's name as a class name stem.
     *
     * @throws UnusableNameException
     */
    private function className(string $schemaName, string $position): string
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
     * Whether a schema's expansion depends on where the walk entered it: it holds
     * a recursion marker aimed at a schema that is not itself, or inside it.
     *
     * A marker back at the schema itself, or at one written inside it, is cut at
     * the same place whichever way the schema is reached, so `Address` containing
     * an `Address` is the same shape everywhere. One aimed at a schema above it,
     * `Author` inside `Book` inside `Author`, is cut where the entry happened to
     * be.
     */
    private static function dependsOnEntry(Schema $schema, ?string $source): bool
    {
        $inside = $source === null ? [] : [$source];
        $targets = [];
        self::collect($schema, $inside, $targets);

        return array_diff($targets, $inside) !== [];
    }

    /**
     * @param  list<string>  $inside  the positions of the named schemas written in the tree
     * @param  list<string>  $targets  where each recursion marker in it points
     */
    private static function collect(Schema $schema, array &$inside, array &$targets): void
    {
        if ($schema->recursesTo !== null) {
            $targets[] = $schema->recursesTo;

            return;
        }

        if ($schema->source !== null) {
            $inside[] = $schema->source;
        }

        foreach ([...array_values($schema->properties), ...$schema->allOf, ...($schema->items === null ? [] : [$schema->items])] as $child) {
            self::collect($child, $inside, $targets);
        }
    }

    /**
     * Whether a file part is written anywhere in a schema, a nested one and an
     * `allOf` branch included.
     *
     * A recursion marker has nothing under it, so it ends the walk.
     */
    private static function hasFilePart(Schema $schema): bool
    {
        if ($schema->isFilePart) {
            return true;
        }

        foreach ([...array_values($schema->properties), ...$schema->allOf, ...($schema->items === null ? [] : [$schema->items])] as $child) {
            if (self::hasFilePart($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A position with one step more: a pointer takes it as it is, and a whole
     * file, which has no `#` yet, gains one.
     */
    private static function within(string $position, string $step): string
    {
        return str_contains($position, '#') ? $position.$step : $position.'#'.$step;
    }

    private static function studly(string $segment): string
    {
        $studly = (string) preg_replace('/[^A-Za-z0-9_]/', '', Str::studly($segment));

        return $studly === '' ? 'Property' : $studly;
    }

    /**
     * The PHP property a contract key becomes: the key when it is an
     * identifier, and a derived name when it is not.
     */
    private static function propertyName(string $key): string
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
    private static function enumKind(array $values): ?string
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
