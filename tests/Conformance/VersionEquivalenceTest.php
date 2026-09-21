<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

// The first equivalence class of the conformance suite, and the one the version
// strategy exists for: a contract written as 3.0 and the same contract written
// as 3.1 must come out of the pipeline identical. Nothing downstream is allowed
// to be able to tell which version was read.
//
// It is a conformance test rather than a unit test because what it pins is the
// behaviour of the whole reading engine, including the parser we do not own.
// @see AGENTS.md — "Automated tests are required"

/**
 * @return list<Operation>
 */
function extractEquivalenceFixture(string $name): array
{
    return ReadOutcome::read(new SpecDocumentReader, specFixturePath('equivalence/'.$name))->operations;
}

it('reads the pair as the two different versions they claim to be', function (): void {
    $reader = new SpecDocumentReader;

    expect($reader->read(specFixturePath('equivalence/same-contract-3.0.yaml'))->document?->version)
        ->toBe(SpecVersion::V3_0)
        ->and($reader->read(specFixturePath('equivalence/same-contract-3.1.yaml'))->document?->version)
        ->toBe(SpecVersion::V3_1);
});

/**
 * Every schema keyword, read back as plain data.
 *
 * A `Contract\Schema` is a tree, and the comparison has to reach its leaves:
 * the version differences are concentrated there rather than in the operation
 * around it.
 *
 * @return array<string, mixed>|null
 */
function describeSchema(?Schema $schema): ?array
{
    if ($schema === null) {
        return null;
    }

    return [
        'types' => array_map(static fn ($type): string => $type->value, $schema->types),
        'nullable' => $schema->isNullable(),
        'format' => $schema->format,
        'required' => $schema->required,
        'enum' => $schema->enum,
        'examples' => $schema->examples,
        'bounds' => [
            $schema->minimum,
            $schema->maximum,
            $schema->exclusiveMinimum,
            $schema->exclusiveMaximum,
        ],
        'lengths' => [$schema->minLength, $schema->maxLength, $schema->minItems, $schema->maxItems],
        'pattern' => $schema->pattern,
        'multipleOf' => $schema->multipleOf,
        'uniqueItems' => $schema->uniqueItems,
        'readOnly' => $schema->readOnly,
        'writeOnly' => $schema->writeOnly,
        'additionalProperties' => $schema->additionalProperties,
        'isFilePart' => $schema->isFilePart,
        'contentMediaType' => $schema->contentMediaType,
        'recursesTo' => $schema->recursesTo,
        'items' => describeSchema($schema->items),
        'allOf' => array_map(describeSchema(...), $schema->allOf),
        'properties' => array_map(describeSchema(...), $schema->properties),
    ];
}

// Every field the extractor produces, not a sample of them — this is the test
// that pins the version strategy's entire promise: nothing downstream, not even
// a reviewer reading a diff, may be able to tell which version was read.
it('extracts one contract from two spellings of it', function (): void {
    $describe = static fn (Operation $operation): array => [
        'index' => $operation->index,
        'identity' => $operation->identity(),
        'template' => $operation->path->template,
        'parameters' => $operation->path->parameterNames,
        'operationId' => $operation->operationId,
        'tags' => $operation->tags,
        'audience' => $operation->audience,
        'lifecycle' => $operation->lifecycle,
        'deprecated' => $operation->deprecated,
        'sunset' => $operation->sunset,
        'security' => $operation->security === null
            ? null
            : array_map(
                static fn (SecurityRequirement $requirement): array => $requirement->schemes,
                $operation->security
            ),
        'requestBody' => $operation->requestBody === null
            ? null
            : [
                'required' => $operation->requestBody->required,
                'content' => array_map(describeSchema(...), $operation->requestBody->content),
            ],
        'responses' => array_map(
            static fn (Response $response): array => [
                'status' => $response->status,
                'content' => array_map(describeSchema(...), $response->content),
            ],
            $operation->responses
        ),
        'queryParameters' => array_map(
            static fn (QueryParameter $parameter): array => [
                'name' => $parameter->name,
                'required' => $parameter->required,
                'schema' => describeSchema($parameter->schema),
            ],
            $operation->queryParameters
        ),
    ];

    expect(array_map($describe, extractEquivalenceFixture('same-contract-3.1.yaml')))
        ->toBe(array_map($describe, extractEquivalenceFixture('same-contract-3.0.yaml')));
});

// Stated separately so a failure says which half broke: the counts matching
// tells you the documents describe the same surface, the comparison above tells
// you every detail of it survived the version difference.
it('finds the same operations in both', function (string $fixture): void {
    expect(extractEquivalenceFixture($fixture))->toHaveCount(4);
})->with(['same-contract-3.0.yaml', 'same-contract-3.1.yaml']);
