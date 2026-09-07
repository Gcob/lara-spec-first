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
    | The specification — DONE
    |--------------------------------------------------------------------------
    |
    | One root document. Multi-file contracts are written as local `$ref`s from
    | it, which the reader already resolves, so a list of roots would answer a
    | question nobody has asked yet and would raise several nothing settles:
    | which document's order wins, and whether each gets its own generated tree.
    | Widening this to a list later is non-breaking; narrowing it would not be.
    |
    | Read only by the build-time commands. Nothing at runtime opens it.
    |
    | See docs/guide/code-generation/index.md — "The runtime never sees the spec".
    |
    */

    'spec' => [
        'path' => 'openapi.yaml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote references — DONE
    |--------------------------------------------------------------------------
    |
    | A `$ref` pointing at a URL turns a specification file into a network
    | client running with this application's credentials and network position,
    | so nothing is fetched unless the host is named here.
    |
    | Empty is the safe default: no host means no remote reference at all. Once
    | a host is named, `spec:build --update-refs` fetches it once and commits
    | the copy under `vendor_path`; every build after that resolves the
    | reference against the committed copy, never the network.
    |
    | See docs/guide/remote-references.md.
    |
    */

    'remote_references' => [
        'allowed_hosts' => [],
        'vendor_path' => 'openapi-external-refs',
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
    | See docs/guide/code-generation/index.md — "Where generated code lives".
    |
    */

    'generated' => [
        'path' => 'app/Http/Generated',
        'namespace' => 'App\\Http\\Generated',
    ],

    /*
    |--------------------------------------------------------------------------
    | What `spec:make` scaffolds — DONE
    |--------------------------------------------------------------------------
    |
    | The namespace `spec:make` proposes when it offers to write `x-controller`
    | into your specification. Only the namespace: the directory follows from your
    | own PSR-4 map, so asking for both would be two places that can disagree.
    |
    | It is a proposal rather than a rule. The value that ends up in the document
    | is the one you accepted, and from then on that document decides the class
    | name — this key never renames anything it already wrote.
    |
    | See docs/guide/controllers.md — "spec:make is the only way in".
    |
    */

    'make' => [
        'controllers' => 'App\Http\Controllers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation lifecycle — DONE
    |--------------------------------------------------------------------------
    |
    | How far ahead `spec:doctor` mentions an `x-sunset` date, in days. It lands
    | in CI while there is still time to act, which is the only reason a horizon
    | is worth configuring at all: 90 days is a release cycle for most teams and
    | a rounding error for some.
    |
    | It decides what is *mentioned*, never what fails. An approaching date is
    | printed in the report's Lifecycle section beside the protection report,
    | not as a finding, so a horizon nobody tuned can never turn a green
    | pipeline red on a day nobody committed anything. The rules that do fail a
    | run — a deprecation with no removal date, a date already passed, a date
    | that cannot be read — do not read this key. Set it to 0 to print no
    | upcoming date at all.
    |
    | A whole number of days, or a string of digits. Anything else — a float, a
    | negative count — falls back to the default rather than being truncated
    | into a horizon nobody wrote.
    |
    | See docs/guide/lifecycle.md — "The doctor rules that follow".
    |
    */

    'lifecycle' => [
        'sunset_horizon_days' => 90,
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
    | See docs/guide/code-generation/response-dtos.md — "Overriding a factory".
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
    | See docs/guide/code-generation/publishing.md — "The specification the build
    | reads is private".
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
