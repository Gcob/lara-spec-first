<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Requests\CreateEventRequest;
use App\Models\Event;
use Illuminate\Support\Carbon;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class CreateEventController extends \App\Http\Generated\Controllers\CreateEventController
{
    public function routeAction(CreateEventRequest $request): mixed
    {
        // The request has already checked the body against the contract, so this only stores it.
        $attributes = $request->dto()->toArray();

        // Why: `starts_at` is an instant. The `datetime` cast keeps the clock digits of `19:00:00-05:00` and drops the
        // offset, so the instant is converted to UTC first.
        $attributes['starts_at'] = Carbon::parse($attributes['starts_at'])->utc();

        $event = Event::create($attributes);

        return response()->json($event, 201);
    }
}
