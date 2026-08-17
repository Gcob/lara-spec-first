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

];
