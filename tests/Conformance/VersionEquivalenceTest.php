<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

// The first equivalence class of the conformance suite, and the one the version
// strategy exists for: a contract written as 3.0 and the same contract written
// as 3.1 must come out of the pipeline identical. Nothing downstream is allowed
// to be able to tell which version was read.
//
// It is a conformance test rather than a unit test because what it pins is the
// behaviour of the whole reading engine, including the parser we do not own.
// See docs/ROADMAP.md.

/**
 * @return list<Operation>
 */
function extractEquivalenceFixture(string $name): array
{
    return (new OperationExtractor)->extract(
        (new SpecDocumentReader)->read(specFixturePath('equivalence/'.$name))
    );
}

it('reads the pair as the two different versions they claim to be', function (): void {
    $reader = new SpecDocumentReader;

    expect($reader->read(specFixturePath('equivalence/same-contract-3.0.yaml'))->version)
        ->toBe(SpecVersion::V3_0)
        ->and($reader->read(specFixturePath('equivalence/same-contract-3.1.yaml'))->version)
        ->toBe(SpecVersion::V3_1);
});

it('extracts one contract from two spellings of it', function (): void {
    $describe = static fn (Operation $operation): array => [
        'index' => $operation->index,
        'identity' => $operation->identity(),
        'template' => $operation->path->template,
        'parameters' => $operation->path->parameterNames,
        'operationId' => $operation->operationId,
    ];

    expect(array_map($describe, extractEquivalenceFixture('same-contract-3.1.yaml')))
        ->toBe(array_map($describe, extractEquivalenceFixture('same-contract-3.0.yaml')));
});

// Stated separately so a failure says which half broke: the counts matching
// tells you the documents describe the same surface, the comparison above tells
// you every detail of it survived the version difference.
it('finds the same operations in both', function (string $fixture): void {
    expect(extractEquivalenceFixture($fixture))->toHaveCount(3);
})->with(['same-contract-3.0.yaml', 'same-contract-3.1.yaml']);
