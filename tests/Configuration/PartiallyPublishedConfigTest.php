<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Tests\PartiallyPublishedConfigTestCase;

uses(PartiallyPublishedConfigTestCase::class);

// Laravel's own mergeConfigFrom merges one level deep. For a nested config that
// is the wrong depth: an application that publishes the file and edits a single
// value replaces the whole sub-array, and every key added to that section in a
// later release arrives missing without a word.

it('keeps what the application published', function (): void {
    expect(config('lara-spec-first.remote_references.allowed_hosts'))->toBe(['schemas.example.com']);
});

it('leaves alone a key the application invented', function (): void {
    expect(config('lara-spec-first.a_setting_this_package_does_not_have'))->toBeTrue();
});

it('fills in a nested default the published file never had', function (): void {
    // The published array above carries `remote_references` without this key.
    // A one-level merge would have dropped it; the package must still see its
    // own default.
    config()->set('lara-spec-first.remote_references.a_future_setting', null);

    expect(config('lara-spec-first.remote_references'))->toHaveKey('allowed_hosts');
});
