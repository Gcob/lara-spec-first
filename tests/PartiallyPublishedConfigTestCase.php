<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Boots the package underneath a configuration the application published and
 * then edited, leaving keys out.
 *
 * The environment is set before package providers register, which is exactly
 * when a real `config/lara-spec-first.php` would already be loaded — so this
 * puts the provider in the position it is actually in rather than simulating
 * one.
 */
abstract class PartiallyPublishedConfigTestCase extends TestCase
{
    /**
     * A published file that names one host and knows nothing of any other key,
     * the way a file copied out of an older release would.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('lara-spec-first', [
            'remote_references' => [
                'allowed_hosts' => ['schemas.example.com'],
            ],
            'a_setting_this_package_does_not_have' => true,
        ]);
    }
}
