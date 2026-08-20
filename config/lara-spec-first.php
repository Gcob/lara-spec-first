<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
|
| Each block below is marked DONE, STARTED or TODO. A TODO block is inert:
| changing it has no effect, and nothing will tell you so.
|
| Key names stay provisional until the first release. The document each block
| cites owns the reasoning; this file is only its shape.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Remote references — STARTED
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
    | Where generated code lives — STARTED
    |--------------------------------------------------------------------------
    |
    | `path` is live: it is where the service provider looks for the generated
    | `routes.php` at boot, and setting it empty throws rather than quietly
    | registering nothing. `namespace` is not read yet — the build that emits
    | classes into it does not exist.
    |
    | Under `app/` because it is application code you will read, extend and
    | debug, not a build artefact hidden away. One configurable root, with
    | fixed sub-namespaces beneath it (`Controllers`, `Data`), so that
    | .gitignore stays one line if you choose to ignore the tree.
    |
    | Path and namespace are two settings rather than one: deriving either from
    | the other means guessing at your autoload map. PSR-4 requires the
    | directory segment and the namespace segment to match including case,
    | which is why every segment below is capitalised.
    |
    | See docs/guide/code-generation.md — "Where generated code lives".
    |
    */

    'generated' => [
        'path' => 'app/Http/Generated',
        'namespace' => 'App\\Http\\Generated',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where your own overrides are looked for — TODO
    |--------------------------------------------------------------------------
    |
    | A DTO factory is overridden by writing a class that extends the generated
    | one, anywhere in the directories named here. Empty by default and nothing
    | is scanned until a project says where to look: scanning the whole
    | application would mean touching every autoloaded class, vendor included,
    | on every build, for a feature a handful of DTOs will ever use.
    |
    | Controllers need no entry here. Their custom class is named by
    | `x-controller` in the specification, so the build looks it up rather than
    | searching for it.
    |
    | See docs/guide/code-generation.md — "Overriding a factory".
    |
    */

    'overrides' => [
        'scan' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing a sanitized specification — TODO
    |--------------------------------------------------------------------------
    |
    | The specification this package reads is private. `x-model` gives away
    | your Eloquent class names and `x-controller` your namespace layout, and
    | neither means anything to a consumer of the API.
    |
    | The disk is the switch. Null publishes nothing; naming one emits a
    | sanitized copy: every `x-`
    | extension is dropped except the ones kept below, and operations marked
    | `x-audience: internal` are removed entirely, along with the components,
    | path items and tags left unreferenced behind them.
    |
    | The disk is Laravel's own, so the same setting serves local storage, S3
    | or a CDN. `public` rather than the default `local` disk, because `local`
    | is rooted at storage/app/private and is deliberately unreachable over
    | HTTP — and serving this file needs `php artisan storage:link`.
    |
    | The keep list denies by default: it says what to keep, never what to
    | remove, so a project's own new extension is dropped rather than leaked by
    | omission. The defaults are the extensions a consumer can act on.
    | `x-audience` is absent on purpose: internal operations are removed
    | entirely, so every one that survives is public and the key would publish
    | a constant.
    |
    | See docs/guide/code-generation.md — "The specification the build reads is
    | private".
    |
    */

    'publish' => [
        'disk' => null,
        'path' => 'openapi.yaml',
        'keep_extensions' => ['x-lifecycle', 'x-sunset'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination — TODO
    |--------------------------------------------------------------------------
    |
    | OpenAPI has no vocabulary for pagination and the community never
    | converged on one, so there is no name to match by and nothing to detect.
    | A driver knows where the structure lives; the mapping says what this
    | project's fields are called.
    |
    | Null does nothing at all: paginated endpoints are treated as ordinary
    | ones until a project states its convention. One driver ships, `laravel`,
    | matching Laravel's own paginator envelope.
    |
    | Note that `driver` being a string presupposes resolution by name, while
    | how a driver gets registered is still open. The key is settled; the
    | mechanism it names is not.
    |
    | See docs/guide/pagination.md and docs/guide/drivers.md.
    |
    */

    'pagination' => [
        'driver' => null,

        'mapping' => [
            'page' => 'page',
            'size' => 'per_page',
            'collection' => 'data',
            'total' => 'meta.total',
            'last_page' => 'meta.last_page',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting — TODO
    |--------------------------------------------------------------------------
    |
    | Same problem as pagination, same shape of answer. Two drivers ship:
    | `headers` reads a limit from documented response headers, `extension`
    | from a custom `x-` extension.
    |
    | A mapping always describes windows, plural. One window is written without
    | a name, as below, and resolves internally to the name `default`. Several
    | are named to tell them apart:
    |
    |     'mapping' => [
    |         'minute' => ['limit' => 'X-RateLimit-Limit-Minute', ...],
    |         'day' => ['limit' => 'X-RateLimit-Limit-Day', ...],
    |     ],
    |
    | Mixing the two levels in one mapping is an error rather than a guess: a
    | string is a field name, an array is a window.
    |
    | Same caveat as pagination: the key is settled, the registration mechanism
    | behind it is not.
    |
    | See docs/guide/rate-limiting.md and docs/guide/drivers.md.
    |
    */

    'rate_limiting' => [
        'driver' => null,

        'mapping' => [
            'limit' => 'RateLimit-Limit',
            'remaining' => 'RateLimit-Remaining',
            'reset' => 'RateLimit-Reset',
        ],
    ],

];
