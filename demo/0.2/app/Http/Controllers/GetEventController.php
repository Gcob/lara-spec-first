<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class GetEventController extends \App\Http\Generated\Controllers\GetEventController
{
    public function routeAction(string $event): mixed
    {
        // 0.2.0 does not check a path parameter: the 404 for an event that does not exist is this code's.
        return Event::findOrFail($event);
    }
}
