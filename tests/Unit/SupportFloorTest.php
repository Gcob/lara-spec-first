<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// The Laravel range composer.json declares is a promise only while a CI job
// installs each of its floors. The lowest job checks what Composer really
// installed (scripts/check-lowest.sh); these tests check what the files say, so
// a red one names the file to fix before a run has to prove it.
//
// A CI job narrows composer.json to one Laravel before it runs the suite (the
// pin step of tests.yml, which sets LSF_LARAVEL_PINNED), so there the declared
// range is no longer readable and these tests skip. The static job installs
// unpinned and runs them (`composer test -- --filter=SupportFloor`); the lowest
// job's guard also turns a leg whose pin names a floor Composer cannot install red.

function composerIsPinned(): bool
{
    return getenv('LSF_LARAVEL_PINNED') !== false;
}

function pinnedSkipMessage(): string
{
    return 'LSF_LARAVEL_PINNED is set: composer.json is pinned to one Laravel, so the declared range is not readable here. '
        .'Run `composer test` on a checkout nobody pinned.';
}

function pinHint(): string
{
    return ' (if composer.json is pinned to one Laravel on purpose, run with LSF_LARAVEL_PINNED=1)';
}

/**
 * @return array{range: string, alternatives: list<string>}
 */
function declaredLaravelRange(): array
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    assert(is_array($composer));

    $range = $composer['require']['illuminate/support'] ?? null;
    assert(is_string($range));

    return [
        'range' => $range,
        'alternatives' => array_map('trim', explode('||', $range)),
    ];
}

/**
 * @return list<string>
 */
function workflowConstraints(string $job): array
{
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml');
    assert(is_array($workflow));

    $legs = $workflow['jobs'][$job]['strategy']['matrix']['include'] ?? [];
    assert(is_array($legs));

    return array_values(array_map(
        static fn (array $leg): string => (string) ($leg['constraint'] ?? ''),
        $legs,
    ));
}

it('declares one Laravel range for routing and support', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    assert(is_array($composer));

    expect($composer['require']['illuminate/routing'] ?? null)
        ->toBe(
            $composer['require']['illuminate/support'] ?? null,
            'illuminate/routing and illuminate/support declare different ranges in composer.json: give them one, '
            .'or the lowest job pins a floor for one and not the other'.pinHint(),
        );
})->skip(composerIsPinned(), pinnedSkipMessage());

it('runs every test cell on a floor composer.json declares', function (): void {
    ['range' => $range, 'alternatives' => $alternatives] = declaredLaravelRange();
    $cells = workflowConstraints('test');

    foreach ($cells as $constraint) {
        expect(in_array($constraint, $alternatives, true))
            ->toBeTrue("a test cell in tests.yml installs {$constraint}, which composer.json does not declare ({$range}): change one to match the other".pinHint());
    }

    foreach ($alternatives as $alternative) {
        expect(in_array($alternative, $cells, true))
            ->toBeTrue("composer.json declares {$alternative}, and no test cell in tests.yml installs it: add a cell".pinHint());
    }
})->skip(composerIsPinned(), pinnedSkipMessage());

it('installs every declared floor in a lowest leg', function (): void {
    ['range' => $range, 'alternatives' => $alternatives] = declaredLaravelRange();
    $legs = workflowConstraints('lowest');

    foreach ($alternatives as $alternative) {
        expect(count(array_keys($legs, $alternative, true)))
            ->toBe(1, "composer.json declares {$alternative}, and the lowest job in tests.yml has "
                .(in_array($alternative, $legs, true) ? 'more than one leg' : 'no leg')
                .' for it: the lowest job installs no floor of its own, add one leg per declared alternative'.pinHint());
    }

    foreach ($legs as $constraint) {
        expect(in_array($constraint, $alternatives, true))
            ->toBeTrue("a lowest leg in tests.yml installs {$constraint}, which composer.json does not declare ({$range}): change one to match the other".pinHint());
    }
})->skip(composerIsPinned(), pinnedSkipMessage());
