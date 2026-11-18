<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
