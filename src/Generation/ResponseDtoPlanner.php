<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

/**
 * Works out every response DTO of a build: its name, its properties, and which operation answers with it.
 *
 * **A response DTO mirrors its schema.** The request side decides what a request can promise and builds two types per
 * body; a response has no such question, so a DTO has the properties the schema states, a required one first and an
 * optional one `Optional`, and the type mapping is the one the input side uses ({@see DtoPlanner}).
 *
 * **A DTO is named after the schema it describes, without `Input`**, so `$ref: User` is `UserDto` and the request that
 * reads it is `UserInputDto`.
 *
 * **Built before it is written**, for the reason the input planner gives: a refusal costs nothing.
 *
 * @internal Not public API.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
final class ResponseDtoPlanner extends DtoPlanner
{
    /**
     * The operations that answer with each DTO, by DTO and in document order.
     *
     * @var array<string, list<array{label: string, status: string}>>
     */
    private array $readers = [];

    /**
     * @param  list<Operation>  $operations  in document order
     *
     * @throws UnusableNameException two schemas under one name, a name that is not a class name, or two keys under one
     *                               property name
     * @throws ConflictingInputException an `allOf` whose branches disagree
     */
    public function plan(array $operations): ResponsePlan
    {
        $statuses = [];

        foreach ($operations as $operation) {
            $statuses[$operation->label()] = [];

            foreach ($operation->responses as $response) {
                $statuses[$operation->label()][] = $this->status($operation, $response);
            }
        }

        $dtos = [];

        foreach ($this->registry as $shortName => $entry) {
            $dtos[] = new PlannedResponseDto(
                $shortName,
                $entry['position'],
                $entry['properties'],
                $this->readers[$shortName] ?? [],
                $entry['naming'],
                $entry['findings'],
            );
        }

        return new ResponsePlan($dtos, $statuses);
    }

    protected function suffix(): string
    {
        return 'Dto';
    }

    protected function isFile(Schema $schema): bool
    {
        return false;
    }

    protected function unrolls(Schema $site, ?Schema $ancestor): bool
    {
        return $site->recursesTo !== null && $ancestor !== null && $site->allOf === [];
    }

    protected function mixedNote(): string
    {
        return '';
    }

    protected function unbuiltRecursionFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, which is not a single named schema, so there is no class to build it into '
                .'and it is `mixed`.',
            $segment,
            $position,
        );
    }

    protected function propertiesBesideFinding(string $segment, string $ancestor): string
    {
        return sprintf(
            '`%s` writes properties beside the reference, which the class of `%s` does not carry, so it is `mixed`.',
            $segment,
            $ancestor,
        );
    }

    protected function ancestorNotAnObjectFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, which is not an object with properties, so it is `mixed`: there is no class '
                .'to read it into.',
            $segment,
            $position,
        );
    }

    protected function registerNested(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string {
        return $this->register($shortName, $written, $merged, $position, $base, $origin, $naming, $named);
    }

    /**
     * What one declared status answers with: the DTO of its JSON body, or the reason it has none.
     *
     * **A list has no class of its own** ({@see StatusResponse}): the body is an array and its items are the DTO, one
     * level at a time for a list of lists.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function status(Operation $operation, Response $response): StatusResponse
    {
        $status = $response->status;

        if ($response->content === []) {
            return StatusResponse::without($status, 'it declares no body');
        }

        $json = self::jsonSchemas($response);

        if ($json === []) {
            return StatusResponse::without($status, 'it declares no JSON media type');
        }

        [$mediaType, $written] = $json[0];

        foreach ($json as [, $other]) {
            if (! $other->sameShapeAs($written)) {
                return StatusResponse::without($status, sprintf(
                    'it declares two JSON media types with different schemas (%s)',
                    implode(' and ', array_map(
                        static fn (array $entry): string => '`'.CommentText::safe($entry[0]).'`',
                        array_slice($json, 0, 2),
                    )),
                ));
            }
        }

        $this->identity = $operation->label();
        $label = 'the `'.$status.'` response';
        $stem = self::stem($operation);
        $base = $stem.self::statusName($status).'Response';
        $inline = DocumentPointer::forOperation($operation).'/responses/'.DocumentPointer::escape($status)
            .'/content/'.DocumentPointer::escape($mediaType).'/schema';
        $merged = AllOfMerger::merge($written, $this->identity, $label);
        $list = false;
        $nullable = $merged->isNullable();

        while ($merged->soleType() === SchemaType::Array) {
            if ($merged->items === null) {
                return StatusResponse::without($status, 'its body is a list with no `items`');
            }

            $written = $merged->items;
            $merged = AllOfMerger::merge($written, $this->identity, $label);
            $nullable = $nullable || $merged->isNullable();
            $inline = self::within($inline, '/items');
            $base .= 'Item';
            $list = true;
        }

        if (! RuleSetBuilder::isObjectBody($merged)) {
            return StatusResponse::without(
                $status,
                $list ? 'its body is a list of values that are not objects' : 'its body is not an object or a list',
            );
        }

        [$name, $source] = $this->nameOf($written, $merged);

        $this->ancestors = RuleSetBuilder::definitions($written, $merged, $this->identity, $label);

        if ($name === null || $source === null) {
            $shortName = $this->register(
                $base.$this->suffix(),
                $written,
                $merged,
                $inline,
                $base,
                sprintf('derived from the operation "%s" and the status "%s"', $operation->label(), $status),
                sprintf(
                    'Class name derived from the operation\'s name, `%s`, and the status `%s`, because the %s is '
                        .'written inline and has no name of its own.',
                    $stem,
                    $status,
                    $list ? 'list\'s item' : 'response',
                ),
                false,
            );
        } else {
            $named = $this->className($name, $source);
            $shortName = $this->register(
                $named.$this->suffix(),
                $written,
                $merged,
                $source,
                $named,
                'the schema\'s name',
                self::namedAfter($name, $source),
                true,
            );
        }

        $reader = ['label' => $this->identity, 'status' => $status];

        if (! in_array($reader, $this->readers[$shortName] ?? [], true)) {
            $this->readers[$shortName][] = $reader;
        }

        return StatusResponse::of($status, $shortName, $list, $nullable);
    }

    /**
     * The operation's name as a class-name stem: its `operationId`, or its method and path when it has none.
     *
     * The same derivation the generated request takes its name from, so `CreateUserRequest` and
     * `CreateUser201ResponseDto` read as one endpoint.
     */
    private static function stem(Operation $operation): string
    {
        $declared = $operation->operationId === null ? null : DerivedName::fromOperationId($operation->operationId);

        return $declared ?? DerivedName::fromMethodAndPath($operation);
    }

    /**
     * A status as the part of a class name that follows the operation: `201`, `2XX`, `Default`.
     */
    private static function statusName(string $status): string
    {
        return strtolower($status) === 'default'
            ? 'Default'
            : strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $status));
    }

    /**
     * The media type and schema of each JSON body a response writes, in document order: none when it has no body, or
     * only media types that are not JSON.
     *
     * @return list<array{0: string, 1: Schema}>
     */
    private static function jsonSchemas(Response $response): array
    {
        $found = [];

        foreach ($response->content as $mediaType => $schema) {
            $lower = strtolower((string) $mediaType);

            if ($lower === 'application/json' || str_ends_with($lower, '+json')) {
                $found[] = [(string) $mediaType, $schema];
            }
        }

        return $found;
    }

    /**
     * Put one DTO in the registry and build its properties, or recognize it as one already there.
     *
     * Registered *before* its properties are built, so a node that recurses back to it finds it instead of building it
     * again.
     *
     * @return string the short name
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function register(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string {
        if (isset($this->registry[$shortName])) {
            $existing = $this->registry[$shortName];

            // A named schema is identified by where it is written, and an inline one by what it says, for the reason
            // the input planner gives.
            $same = $named && $existing['named']
                ? $existing['position'] === $position
                : ! $named && ! $existing['named'] && $existing['schema']->shapeKey() === $merged->shapeKey();

            if (! $same) {
                throw UnusableNameException::responseDtoClaimedTwice($shortName, $existing['position'], $position);
            }

            return $shortName;
        }

        $this->registry[$shortName] = [
            'partial' => false,
            'named' => $named,
            'multipart' => false,
            'schema' => $merged,
            'position' => $position,
            'base' => $base,
            'origin' => $origin,
            'naming' => $naming,
            'properties' => [],
            'findings' => [],
        ];

        $required = [];
        $optional = [];
        $claimed = [];

        foreach ($merged->properties as $key => $property) {
            $key = (string) $key;

            // Nothing a response sends is `writeOnly`, so the type has no property for it.
            if (AllOfMerger::merge($property, $this->identity, '`'.$key.'`')->writeOnly) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is `writeOnly`, so a response never sends it and this type has no property for it.',
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

            if (in_array($key, $merged->required, true)) {
                $required[] = new InputDtoProperty($key, $name, $type, false);
            } else {
                $optional[] = new InputDtoProperty($key, $name, $type, true);
            }
        }

        // An object with no properties is a map: its keys are the data, so the one property holds them all. Typed by
        // its value schema, always, since a response shares its payload with no `query` parameter.
        if ($merged->properties === []) {
            $required[] = new InputDtoProperty(
                'additionalProperties',
                'additionalProperties',
                $this->map($merged, $base, 'additionalProperties', $position, false, $shortName),
                false,
                wholePayload: true,
            );
        }

        $values = $merged->properties === [] ? null : $merged->additionalPropertiesSchema;

        if ($values !== null && ! RuleSetBuilder::allowsAnyValue($values)) {
            $this->registry[$shortName]['findings'][] = '`additionalProperties` types the values of the keys that are not '
                .'declared, but this type carries only the declared properties, so those values are not read.';
        }

        $this->registry[$shortName]['properties'] = [...$required, ...$optional];

        return $shortName;
    }
}
