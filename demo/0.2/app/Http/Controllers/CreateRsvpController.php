<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Requests\CreateRsvpRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class CreateRsvpController extends \App\Http\Generated\Controllers\CreateRsvpController
{
    public function routeAction(CreateRsvpRequest $request, string $event): mixed
    {
        $event = Event::findOrFail($event);

        // The contract declares the 409, and this code is what keeps the rule: nothing in the package counts RSVPs.
        if ($event->rsvps()->count() >= $event->capacity) {
            return response()->json(['message' => 'The event is full.'], 409);
        }

        return response()->json($event->rsvps()->create($request->dto()->toArray()), 201);
    }
}
