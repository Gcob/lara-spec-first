<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Doctor\Checks\SecurityCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * @param  list<SecurityRequirement>|null  $security
 */
function securedOperation(string $path, ?array $security, int $index = 0): Operation
{
    return new Operation($index, HttpMethod::Get, PathTemplate::fromString($path), null, security: $security);
}

it('reports an operation that requires a scheme as a package limit this package does not apply yet', function (): void {
    $findings = SecurityCheck::check([
        securedOperation('/orders', [SecurityRequirement::fromSchemes(['bearerAuth' => []])]),
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->section)->toBe('Security')
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('get /orders')
        ->and($findings[0]->message)->toContain('bearerAuth')
        ->and($findings[0]->message)->toContain('does not apply it yet');
});

// The rule doctor.md reserves for security specifically: every affected
// operation, individually, on every run — never folded into a count. This is
// the test that stops a later "tidy-up" from summarizing them.
it('lists every affected operation individually rather than summarizing them', function (): void {
    $findings = SecurityCheck::check([
        securedOperation('/orders', [SecurityRequirement::fromSchemes(['bearerAuth' => []])], 0),
        securedOperation('/invoices', [SecurityRequirement::fromSchemes(['bearerAuth' => []])], 1),
        securedOperation('/payouts', [SecurityRequirement::fromSchemes(['bearerAuth' => []])], 2),
    ]);

    expect($findings)->toHaveCount(3)
        ->and($findings[0]->message)->toContain('/orders')
        ->and($findings[1]->message)->toContain('/invoices')
        ->and($findings[2]->message)->toContain('/payouts');
});

// Empty and absent are the two states that must stay silent, and they are
// different states: `security: []` is an operation that explicitly requires
// nothing — already exactly what this phase delivers — and a missing block
// inherits requirements this package does not read at all.
it('says nothing about an operation that explicitly requires nothing', function (): void {
    expect(SecurityCheck::check([securedOperation('/health', [])]))->toBe([]);
});

it('says nothing about an operation that states no security of its own', function (): void {
    expect(SecurityCheck::check([securedOperation('/health', null)]))->toBe([]);
});

// The schemes inside one requirement are ANDed, so `SecurityRequirement`
// sorts them by name once at construction and the message inherits that order
// rather than the document's — asserted here, with the input written in the
// opposite order, so nobody adds a second sort in the renderer to fix an
// ordering that was never loose.
it('names the scopes a scheme asks for, and both ways requirements combine', function (): void {
    $findings = SecurityCheck::check([
        securedOperation('/orders', [
            SecurityRequirement::fromSchemes(['oauth2' => ['read:orders', 'write:orders'], 'apiKey' => []]),
            SecurityRequirement::fromSchemes(['bearerAuth' => []]),
        ]),
    ]);

    expect($findings[0]->message)->toContain('apiKey and oauth2 (read:orders, write:orders) or bearerAuth');
});

it('reads an operation-level security block off a real document', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('secured-operations.yaml'));
    $findings = SecurityCheck::check($outcome->operations);

    expect($outcome->faults)->toBe([])
        ->and($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('get /orders')
        ->and($findings[1]->message)->toContain('post /orders');
});

// The root block is Open in the support matrix and is read into nothing, so
// the only honest thing to report is that it exists — and only where some
// operation actually inherits it. The fixture's `/inherits` is that operation.
it('reports a root security block as one line rather than as a finding per inheriting operation', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('secured-operations.yaml'));

    expect(SecurityCheck::inheritsUnreadRootRequirements($outcome->document, $outcome->operations))->toBeTrue();
});

// The line asserts two things — the block exists, and an operation inherits it
// — so both are checked. A document that declares a root block and overrides it
// on every single operation has no operation the second half is true of, and the
// block changes nothing this package does, so the honest answer is silence
// rather than a caveat about a risk that does not exist.
it('says nothing about a root block every operation overrides', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('root-security-overridden.yaml'));

    expect($outcome->faults)->toBe([])
        ->and(SecurityCheck::inheritsUnreadRootRequirements($outcome->document, $outcome->operations))->toBeFalse();
});

it('reports no root block on a document that carries none', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect(SecurityCheck::inheritsUnreadRootRequirements($outcome->document, $outcome->operations))->toBeFalse()
        ->and(SecurityCheck::check($outcome->operations))->toBe([]);
});

it('has nothing to say about a document that could not be read at all', function (): void {
    expect(SecurityCheck::inheritsUnreadRootRequirements(null, []))->toBeFalse();
});
