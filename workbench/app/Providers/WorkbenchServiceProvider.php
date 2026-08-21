<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

/**
 * Points the package at the Workbench application's own files.
 *
 * **Why this is needed at all**, because it looks like it should not be: with
 * `laravel: '@testbench'` in testbench.yaml, the application's `base_path()` is
 * the Testbench skeleton under `vendor/`, not `workbench/`. So the package's own
 * defaults — a specification at `openapi.yaml` and a tree at
 * `app/Http/Generated`, both relative to the application root — resolve inside
 * `vendor/`, where nothing is committed and `composer clear` wipes everything.
 *
 * Absolute paths computed from this file put both back in `workbench/`, where a
 * developer can read the contract, edit it, rebuild, and read what changed. The
 * package supports an absolute `generated.path` for exactly this shape of layout.
 *
 * **Only these two keys, deliberately.** This provider is registered in
 * testbench.yaml, which means it also loads in the fresh application
 * `php artisan route:cache` boots. Anything else overridden here would change
 * what that command sees, and the package's own tests would then be measuring
 * the Workbench's configuration rather than the defaults.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * The `workbench/` directory: two levels up from `app/Providers`.
     */
    private const string WORKBENCH = __DIR__.'/../..';

    public function register(): void
    {
        $config = $this->app->make(Repository::class);

        $config->set('lara-spec-first.spec.path', realpath(self::WORKBENCH.'/openapi.yaml') ?: '');

        // Under `workbench/app/` so that the generated classes autoload: this
        // package's composer.json maps `Workbench\App\` to that directory, which
        // is what makes a generated controller reachable at request time.
        $config->set('lara-spec-first.generated.path', self::WORKBENCH.'/app/Http/Generated');
        $config->set('lara-spec-first.generated.namespace', 'Workbench\\App\\Http\\Generated');
    }
}
