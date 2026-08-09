<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests;

use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a real Laravel application around the package, via Testbench.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaraSpecFirstServiceProvider::class,
        ];
    }
}
