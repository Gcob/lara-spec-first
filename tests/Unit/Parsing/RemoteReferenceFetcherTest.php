<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\RemoteReferenceFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

it('returns the response body on success', function (): void {
    $factory = new Factory;
    $factory->fake(['https://schemas.example.com/common.yaml' => 'type: object']);

    $fetcher = new RemoteReferenceFetcher($factory);

    expect($fetcher->fetch('https://schemas.example.com/common.yaml'))->toBe('type: object');
});

it('throws naming the URL when the server answers with an error status', function (): void {
    $factory = new Factory;
    $factory->fake(['https://schemas.example.com/common.yaml' => Factory::response('not found', 404)]);

    $fetcher = new RemoteReferenceFetcher($factory);

    expect(fn () => $fetcher->fetch('https://schemas.example.com/common.yaml'))
        ->toThrow(RemoteReferenceFetchException::class, 'https://schemas.example.com/common.yaml');
});

// The one control the whole vendoring feature rests on: the allowlist checks
// the host written in the document, so a client that silently follows a
// redirect fetches whatever a `3xx` names instead — bypassing the allowlist
// entirely and committing the other host's body under the allowed host's
// directory.
it('refuses a redirect rather than following it to another host', function (): void {
    $factory = new Factory;
    $factory->fake([
        'https://schemas.example.com/common.yaml' => Factory::response(
            '',
            302,
            ['Location' => 'https://evil.example.com/x.yaml'],
        ),
    ]);

    $fetcher = new RemoteReferenceFetcher($factory);

    expect(fn () => $fetcher->fetch('https://schemas.example.com/common.yaml'))
        ->toThrow(RemoteReferenceFetchException::class, 'https://evil.example.com/x.yaml');
});

it('throws when the transport itself fails', function (): void {
    $factory = new Factory;
    $factory->fake(function (): never {
        throw new ConnectionException('Could not resolve host.');
    });

    $fetcher = new RemoteReferenceFetcher($factory);

    expect(fn () => $fetcher->fetch('https://schemas.example.com/common.yaml'))
        ->toThrow(RemoteReferenceFetchException::class);
});
