<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Configuration\ConfigurationMerger;

// The regression this file exists to catch: replacing the merge with Laravel's
// one-level `mergeConfigFrom()` — or with `array_replace` — must make the first
// case below fail. A broken merge raises nothing and logs nothing, it just hands
// back a config missing a key, one release after the mistake.
//
// Tested here rather than through a booted application on purpose: Testbench
// applies `defineEnvironment()` *after* package providers register, so a test
// that publishes a config through the harness overwrites the merged result and
// proves nothing about the merge.

it('keeps a default the published section never declared', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(
        ['remote_references' => ['allowed_hosts' => []]],
        ['remote_references' => ['a_setting_from_an_older_release' => 'kept']],
    );

    expect($merged['remote_references'])->toBe([
        'allowed_hosts' => [],
        'a_setting_from_an_older_release' => 'kept',
    ]);
});

it('lets a published value win over the default', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(
        ['remote_references' => ['allowed_hosts' => []]],
        ['remote_references' => ['allowed_hosts' => ['schemas.example.com']]],
    );

    expect($merged['remote_references']['allowed_hosts'])->toBe(['schemas.example.com']);
});

it('keeps a key the application invented', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(['a' => 1], ['b' => 2]);

    expect($merged)->toBe(['a' => 1, 'b' => 2]);
});

it('descends through several levels', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(
        ['one' => ['two' => ['kept' => true, 'replaced' => 'default']]],
        ['one' => ['two' => ['replaced' => 'published']]],
    );

    expect($merged['one']['two'])->toBe(['kept' => true, 'replaced' => 'published']);
});

// A list is a value the application chose in full. Merging element by element
// would make an entry impossible to remove — and the first list this package
// has is `allowed_hosts`, where being unable to withdraw trust is the wrong
// failure to have.
it('replaces a list rather than merging into it', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(
        ['hosts' => ['a.example.com', 'b.example.com']],
        ['hosts' => ['c.example.com']],
    );

    expect($merged['hosts'])->toBe(['c.example.com']);
});

it('lets an application empty a list', function (): void {
    $merged = ConfigurationMerger::defaultsUnder(['hosts' => ['a.example.com']], ['hosts' => []]);

    expect($merged['hosts'])->toBe([]);
});

it('replaces rather than descends when the shapes disagree', function (mixed $default, mixed $published): void {
    $merged = ConfigurationMerger::defaultsUnder(['key' => $default], ['key' => $published]);

    expect($merged['key'])->toBe($published);
})->with([
    'array over scalar' => ['a string', ['now' => 'nested']],
    'scalar over array' => [['was' => 'nested'], 'a string'],
    'null over array' => [['was' => 'nested'], null],
]);
