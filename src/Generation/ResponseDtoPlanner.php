<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
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
        foreach ($operations as $operation) {
            foreach ($operation->responses as $response) {
                $this->root($operation, $response);
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

        return new ResponsePlan($dtos);
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
     * The DTO one response answers with, when it has one.
     *
     * @throws UnusableNameException
     * @throws ConflictingInputException
     */
    private function root(Operation $operation, Response $response): void
    {
        $written = self::jsonSchema($response);

        if ($written === null) {
            return;
        }

        $this->identity = $operation->label();
        $label = 'the `'.$response->status.'` response';
        $merged = AllOfMerger::merge($written, $this->identity, $label);

        if (! RuleSetBuilder::isObjectBody($merged) || $merged->properties === []) {
            return;
        }

        [$name, $source] = $this->nameOf($written, $merged);

        if ($name === null || $source === null) {
            return;
        }

        $this->ancestors = RuleSetBuilder::definitions($written, $merged, $this->identity, $label);
        $base = $this->className($name, $source);
        $shortName = $this->register(
            $base.$this->suffix(),
            $written,
            $merged,
            $source,
            $base,
            'the schema\'s name',
            self::namedAfter($name, $source),
            true,
        );

        $reader = ['label' => $this->identity, 'status' => $response->status];

        if (! in_array($reader, $this->readers[$shortName] ?? [], true)) {
            $this->readers[$shortName][] = $reader;
        }
    }

    /**
     * The schema of a response's JSON body, or null when it has none: no body, or only media types that are not JSON.
     */
    private static function jsonSchema(Response $response): ?Schema
    {
        foreach ($response->content as $mediaType => $schema) {
            $mediaType = strtolower((string) $mediaType);

            if ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
                return $schema;
            }
        }

        return null;
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

        $this->registry[$shortName]['properties'] = [...$required, ...$optional];

        return $shortName;
    }
}
