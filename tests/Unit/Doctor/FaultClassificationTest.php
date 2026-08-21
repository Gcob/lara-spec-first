<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\FaultClassification;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CircularRemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\MissingVendoredReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserFailedException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;

/**
 * Every class `ReadOutcome::$faults` can actually carry — the ten exceptions
 * the reading pipeline collects instead of throwing. `sectionOf()` has no
 * `default` arm on purpose, so a class missing from this list would fail this
 * test with `LogicException` rather than an unrecognized finding silently
 * disappearing from a real report — see FaultClassification's own docblock.
 *
 * @return list<SpecException>
 */
function everyPipelineFault(): array
{
    return [
        UnreadableDocumentException::missing('spec.yaml'),
        UnsupportedVersionException::missing(),
        InvalidDocumentException::duplicateEndpoint('get /x', 'get /x', 'get /y'),
        ParserFailedException::wrap('spec.yaml', new RuntimeException('boom')),
        CyclicReferenceException::chain(['#/a', '#/b', '#/a']),
        RemoteReferenceException::notAllowed('https://example.com/s.yaml'),
        MissingVendoredReferenceException::notVendored('https://example.com/s.yaml', 'vendor/s.yaml'),
        CircularRemoteReferenceException::chain(['https://a', 'https://b', 'https://a']),
        RemoteReferenceFetchException::failed('https://example.com/s.yaml', 'server error'),
        RejectedConstructException::traceOperation('/debug'),
    ];
}

it('classifies every pipeline fault without throwing', function (): void {
    foreach (everyPipelineFault() as $fault) {
        expect(FaultClassification::sectionOf($fault))->not->toBe('');
        expect(FaultClassification::classOf($fault))->toBeInstanceOf(FindingClass::class);
        expect(FaultClassification::levelOf($fault))->toBeIn([null, ...SupportLevel::cases()]);
    }
});

it('sorts document faults from package limits the way the spec author test would', function (): void {
    expect(FaultClassification::classOf(UnreadableDocumentException::missing('spec.yaml')))
        ->toBe(FindingClass::DocumentFault)
        ->and(FaultClassification::classOf(InvalidDocumentException::duplicateEndpoint('a', 'b', 'c')))
        ->toBe(FindingClass::DocumentFault)
        ->and(FaultClassification::classOf(CyclicReferenceException::chain(['#/a', '#/b', '#/a'])))
        ->toBe(FindingClass::DocumentFault)
        ->and(FaultClassification::classOf(ParserFailedException::wrap('s.yaml', new RuntimeException)))
        ->toBe(FindingClass::DocumentFault);

    expect(FaultClassification::classOf(RejectedConstructException::traceOperation('/debug')))
        ->toBe(FindingClass::PackageLimit)
        ->and(FaultClassification::classOf(RemoteReferenceException::notAllowed('https://e.com/s.yaml')))
        ->toBe(FindingClass::PackageLimit)
        ->and(FaultClassification::classOf(UnsupportedVersionException::missing()))
        ->toBe(FindingClass::PackageLimit);
});

it('gives every package limit a Rejected level, and every document fault none', function (): void {
    expect(FaultClassification::levelOf(RejectedConstructException::traceOperation('/debug')))
        ->toBe(SupportLevel::Rejected)
        ->and(FaultClassification::levelOf(UnsupportedVersionException::missing()))
        ->toBe(SupportLevel::Rejected)
        ->and(FaultClassification::levelOf(UnreadableDocumentException::missing('spec.yaml')))
        ->toBeNull()
        ->and(FaultClassification::levelOf(InvalidDocumentException::duplicateEndpoint('a', 'b', 'c')))
        ->toBeNull();
});

it('sends structural faults to Document validity, reference faults to References, and a Rejected construct to Support findings', function (): void {
    expect(FaultClassification::sectionOf(UnreadableDocumentException::missing('spec.yaml')))
        ->toBe('Document validity')
        ->and(FaultClassification::sectionOf(UnsupportedVersionException::missing()))
        ->toBe('Document validity')
        ->and(FaultClassification::sectionOf(CyclicReferenceException::chain(['#/a', '#/b', '#/a'])))
        ->toBe('References')
        ->and(FaultClassification::sectionOf(RemoteReferenceException::notAllowed('https://e.com/s.yaml')))
        ->toBe('References')
        ->and(FaultClassification::sectionOf(RejectedConstructException::traceOperation('/debug')))
        ->toBe('Support findings');
});

it('builds a Finding carrying the fault message and an optional pointer', function (): void {
    $finding = FaultClassification::fromFault(RejectedConstructException::traceOperation('/debug'), '#/paths/~1debug');

    expect($finding->class)->toBe(FindingClass::PackageLimit)
        ->and($finding->section)->toBe('Support findings')
        ->and($finding->level)->toBe(SupportLevel::Rejected)
        ->and($finding->pointer)->toBe('#/paths/~1debug')
        ->and($finding->message)->toContain('Laravel has no TRACE verb');
});
