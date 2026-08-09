<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests;

use Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a real Laravel application around the package, via Testbench.
 *
 * Note that the Workbench application is deliberately *not* loaded here: its
 * routes return 404 in tests. Workbench is development scaffolding for
 * `composer serve`, and coupling the suite to it would let a broken
 * demonstration route fail the package's own tests.
 *
 * Tests that need routes should define their own fixtures instead, so that what
 * they assert is owned by the test rather than by the sample application.
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
