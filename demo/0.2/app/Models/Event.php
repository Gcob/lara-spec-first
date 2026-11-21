<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    protected $fillable = ['title', 'city', 'starts_at', 'capacity'];

    // The contract's Event has no timestamps, so the answer does not carry them.
    protected $hidden = ['created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    // Why: `starts_at` is an instant. The `datetime` cast keeps the clock digits of `19:00:00-05:00` and drops the offset,
    // so the instant is converted to UTC before it is stored.
    protected function startsAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => Carbon::parse($value)->utc());
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }
}
