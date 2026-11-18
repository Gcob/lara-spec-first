<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Requests\CreateEventRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class CreateEventController extends \App\Http\Generated\Controllers\CreateEventController
{
    public function routeAction(CreateEventRequest $request): mixed
    {
        // The request has already checked the body against the contract, so this line only stores it.
        $event = Event::create($request->dto()->toArray());

        return response()->json($event, 201);
    }
}
