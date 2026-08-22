<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\Checks\DriftCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Generation\BuildPlan;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;

function driftRoot(): string
{
    static $root = null;

    return $root ??= sys_get_temp_dir().'/lsf-drift-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(driftRoot()));
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(driftRoot()));
});

function plannedFile(string $path, string $body = 'body'): GeneratedFile
{
    return new GeneratedFile($path, "<?php\n// ".GeneratedFile::MARKER."\n".$body."\n");
}

it('reports nothing when the plan itself could not be built', function (): void {
    expect(DriftCheck::check(null, driftRoot()))->toBe([]);
});

// The state distinct from drift: a project that has simply never run
// `spec:build` yet, the same legitimate state the service provider itself
// treats as silence.
it('reports nothing when the generated tree does not exist at all', function (): void {
    $plan = new BuildPlan([], [plannedFile('routes.php')]);

    expect(is_dir(driftRoot()))->toBeFalse()
        ->and(DriftCheck::check($plan, driftRoot()))->toBe([]);
});

it('reports nothing when the generated tree already matches the plan', function (): void {
    $files = [plannedFile('routes.php')];
    (new GeneratedTree(driftRoot()))->write($files);

    expect(DriftCheck::check(new BuildPlan([], $files), driftRoot()))->toBe([]);
});

it('reports a file that would be rewritten as a document fault naming spec:build', function (): void {
    (new GeneratedTree(driftRoot()))->write([plannedFile('routes.php', 'old')]);

    $findings = DriftCheck::check(new BuildPlan([], [plannedFile('routes.php', 'new')]), driftRoot());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Drift')
        ->and($findings[0]->message)->toContain('routes.php')
        ->and($findings[0]->message)->toContain('php artisan spec:build');
});

it('reports a generated file the plan no longer contains as something a build would prune', function (): void {
    (new GeneratedTree(driftRoot()))->write([plannedFile('Controllers/Gone.php')]);

    $findings = DriftCheck::check(new BuildPlan([], []), driftRoot());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Controllers/Gone.php')
        ->and($findings[0]->message)->toContain('no longer describes');
});

it('reports one finding per drifted file rather than folding them into a count', function (): void {
    (new GeneratedTree(driftRoot()))->write([
        plannedFile('routes.php', 'old'),
        plannedFile('Controllers/Stale.php'),
    ]);

    $findings = DriftCheck::check(new BuildPlan([], [plannedFile('routes.php', 'new')]), driftRoot());

    expect($findings)->toHaveCount(2);
});
