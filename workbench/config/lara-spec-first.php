<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Workbench application's own configuration
|--------------------------------------------------------------------------
|
| Exactly what a consumer would have: the package's published config file,
| edited. It is loaded before the package registers, and the package then merges
| its own defaults *beneath* whatever is here — so this file names only the keys
| the Workbench needs to differ on, the way a real project's would.
|
| Enabled by `workbench.discovers.config` in testbench.yaml. Without that line
| this file is inert, which is the kind of silence this package refuses
| elsewhere, so it is worth knowing.
|
| **Why the paths are absolute, and why that is not a workaround.** With
| `laravel: '@testbench'` the application root is the Testbench skeleton under
| `vendor/`, so `base_path('openapi.yaml')` — what a real project would write —
| resolves where nothing is committed and `composer clear` wipes it. Computing
| from `__DIR__` puts both back in `workbench/`. The package supports an absolute
| `generated.path` deliberately, for the monorepo layout this is a small version
| of.
|
*/

$workbench = dirname(__DIR__);

return [

    'spec' => [
        'path' => $workbench.'/openapi.yaml',
    ],

    'generated' => [
        // Under `workbench/app/` so the generated classes autoload: this
        // package's composer.json maps `Workbench\App\` to that directory, which
        // is what makes a generated controller reachable at request time.
        'path' => $workbench.'/app/Http/Generated',
        'namespace' => 'Workbench\\App\\Http\\Generated',
    ],

    'make' => [
        // What `spec:make` proposes when it offers to write `x-controller`. The
        // default is `App\Http\Controllers`, which nothing here maps — so the
        // command would offer a class it then refused to place.
        'controllers' => 'Workbench\\App\\Http\\Controllers',
    ],

];
