<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

// The parent below is generated. If PHP cannot find it, run
// `php artisan spec:build`. If it still fails, the specification no longer has an
// `x-controller` pointing here. Note that the spec's git history will show what
// changed.
class UserController extends \Workbench\App\Http\Generated\Controllers\UserController
{
    /**
     * The one method the route reaches, overriding the generated 501.
     *
     * Nothing here knows about this package: it is an ordinary Laravel
     * controller, in the application's own controller directory, and the only
     * unusual thing about it is the class it extends.
     */
    public function routeAction(string $id): array
    {
        return ['id' => $id, 'answered_by' => self::class];
    }
}
