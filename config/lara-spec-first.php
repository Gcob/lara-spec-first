<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Remote references
    |--------------------------------------------------------------------------
    |
    | A `$ref` pointing at a URL turns a specification file into a network
    | client running with this application's credentials and network position,
    | so nothing is fetched unless the host is named here.
    |
    | Empty is the safe default and, for now, the only value that works: naming
    | a host throws rather than silently doing nothing, because the fetching and
    | vendoring behind this setting is not built yet.
    |
    | See docs/guide/remote-references.md.
    |
    */

    'remote_references' => [
        'allowed_hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | The contract artifact
    |--------------------------------------------------------------------------
    |
    | The normalized contract the build writes: what the package honors, with
    | every reference resolved and every OpenAPI version difference absorbed.
    | Comparisons between two versions of a contract are made between artifacts,
    | never between specification documents.
    |
    | Null means "beside the specification", under the name the package owns.
    | Put it wherever you like, with one hard constraint: it has to be committed.
    | It is the baseline breaking-change detection compares against, so a
    | gitignored artifact is not a smaller feature, it is a silently absent one.
    | `storage/` is the trap worth naming — a stock Laravel application ignores
    | everything under `storage/app/`, so an artifact written there disappears
    | without anybody deciding it should.
    |
    | Read by `spec:build`, which does not exist yet.
    |
    | See docs/internals/contract-artifact.md.
    |
    */

    'artifact' => [
        'path' => null,
    ],

];
