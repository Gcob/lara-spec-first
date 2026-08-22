<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Doctor\Checks\LifecycleCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;

/**
 * Every date in this file is compared against a fixed "now" rather than the
 * day the suite happens to run: a lifecycle test that reads the system clock
 * is a test that turns red on its own, which is the very failure mode the
 * approaching-sunset rule is designed around.
 */
function lifecycleNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-06-01T00:00:00Z');
}

function lifecycleOperation(
    string $path,
    bool $deprecated = false,
    ?string $sunset = null,
    Audience $audience = Audience::Public,
    ?Lifecycle $lifecycle = Lifecycle::Beta,
): Operation {
    return new Operation(
        0,
        HttpMethod::Get,
        PathTemplate::fromString($path),
        null,
        audience: $audience,
        lifecycle: $lifecycle,
        deprecated: $deprecated,
        sunset: $sunset,
    );
}

// --- The three rules that gate ---

it('reports a deprecation that states no removal date', function (): void {
    $findings = LifecycleCheck::check([lifecycleOperation('/legacy', deprecated: true)], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Lifecycle')
        ->and($findings[0]->level)->toBeNull()
        ->and($findings[0]->message)->toContain('get /legacy')
        ->and($findings[0]->message)->toContain('no removal date');
});

it('says nothing about a deprecation that states one', function (): void {
    expect(LifecycleCheck::check([
        lifecycleOperation('/legacy', deprecated: true, sunset: '2026-12-31'),
    ], lifecycleNow()))->toBe([]);
});

it('reports a removal date that has passed while the endpoint is still served', function (): void {
    $findings = LifecycleCheck::check([lifecycleOperation('/gone', sunset: '2026-05-31')], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->message)->toContain('2026-05-31')
        ->and($findings[0]->message)->toContain('has passed');
});

it('reports a removal date nothing can read', function (): void {
    $findings = LifecycleCheck::check([lifecycleOperation('/someday', sunset: 'next tuesday')], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->message)->toContain('next tuesday')
        ->and($findings[0]->message)->toContain('not a date this package can read');
});

// A date that only parses by rolling over into the next month answers a
// promise its author never made, so it is unreadable rather than accepted —
// the same rule the reading pipeline applies before this section sees it.
it('treats a date that only parses by rolling into the next month as unreadable', function (): void {
    $findings = LifecycleCheck::check([lifecycleOperation('/impossible', sunset: '2026-02-30')], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('not a date this package can read');
});

it('reads a moment as well as a calendar date, in either spelling of UTC', function (): void {
    $findings = LifecycleCheck::check([
        lifecycleOperation('/atom', sunset: '2026-05-31T23:00:00+00:00'),
        lifecycleOperation('/zulu', sunset: '2026-06-30T12:00:00Z'),
    ], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/atom');
});

it('reports both rules against one operation that breaks both', function (): void {
    $findings = LifecycleCheck::check([
        lifecycleOperation('/gone', deprecated: true, sunset: '2020-01-01'),
    ], lifecycleNow());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('has passed');
});

it('is silent on a contract that states no lifecycle at all', function (): void {
    expect(LifecycleCheck::check([lifecycleOperation('/users')], lifecycleNow()))->toBe([]);
});

// --- The outcome: what the section reports when nothing is wrong ---

it('counts public operations only, and how many of them carry a promise', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/promised', lifecycle: Lifecycle::Stable),
        lifecycleOperation('/unpromised'),
        lifecycleOperation('/internal', audience: Audience::Internal, lifecycle: null),
        lifecycleOperation('/internal-stable', audience: Audience::Internal, lifecycle: Lifecycle::Stable),
    ], lifecycleNow(), 90);

    expect($outcome->publicOperations)->toBe(2)
        ->and($outcome->stablePublicOperations)->toBe(1);
});

it('lists every beta operation, whatever its audience', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/unpromised'),
        lifecycleOperation('/internal-beta', audience: Audience::Internal, lifecycle: Lifecycle::Beta),
        lifecycleOperation('/promised', lifecycle: Lifecycle::Stable),
    ], lifecycleNow(), 90);

    expect($outcome->beta)->toBe(['get /unpromised', 'get /internal-beta']);
});

it('lists a removal date inside the horizon, with the days left', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/soon', sunset: '2026-07-01'),
    ], lifecycleNow(), 90);

    expect($outcome->approachingSunsets)->toHaveCount(1)
        ->and($outcome->approachingSunsets[0]->operation)->toBe('get /soon')
        ->and($outcome->approachingSunsets[0]->sunset)->toBe('2026-07-01')
        ->and($outcome->approachingSunsets[0]->daysRemaining)->toBe(30);
});

it('leaves a removal date beyond the horizon out of the listing', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/later', sunset: '2027-01-01'),
    ], lifecycleNow(), 90);

    expect($outcome->approachingSunsets)->toBe([]);
});

// A date already passed is a finding, and a finding is not repeated as an
// outcome line: the report would state one problem twice.
it('leaves a removal date that has already passed out of the listing', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/gone', sunset: '2020-01-01'),
    ], lifecycleNow(), 90);

    expect($outcome->approachingSunsets)->toBe([]);
});

it('prints no upcoming date at all on a horizon of zero', function (): void {
    $outcome = LifecycleCheck::outcome([
        lifecycleOperation('/soon', sunset: '2026-06-02'),
    ], lifecycleNow(), 0);

    expect($outcome->approachingSunsets)->toBe([]);
});

// --- Reading the configured horizon ---

// The key decides what is mentioned, never what fails, so an unusable value
// falls back instead of refusing to run — and a value that is numeric without
// being a whole number of days falls back rather than being truncated into a
// horizon nobody wrote.
it('reads a whole number of days, and a string of digits, as written', function (mixed $configured, int $expected): void {
    expect(LifecycleCheck::horizonDays($configured))->toBe($expected);
})->with([
    'an integer' => [30, 30],
    'zero, which is a real value rather than a fallback' => [0, 0],
    'a string of digits, as an environment variable would carry it' => ['30', 30],
    'a string of digits with whitespace around it' => [" 30\n", 30],
]);

it('falls back to the default on anything that is not a whole number of days', function (mixed $configured): void {
    expect(LifecycleCheck::horizonDays($configured))->toBe(LifecycleCheck::DEFAULT_HORIZON_DAYS);
})->with([
    'the key missing entirely' => [null],
    'a float, which truncation would silently reinterpret' => [90.5],
    'exponential notation, numeric but not a count of days' => ['1e2'],
    'a negative count of days' => [-30],
    'a negative count written as a string' => ['-30'],
    'a typo' => ['ninety'],
    'the wrong shape altogether' => [['days' => 90]],
    'a boolean' => [true],
]);
