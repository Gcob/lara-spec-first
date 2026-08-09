<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Workbench scaffolds this with the #[Fillable] and #[Hidden] PHP
     * attributes, which only exist from Laravel 13. This package supports
     * Laravel 12 as well, and the property form works on both.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Never serialised, whatever the OpenAPI contract says.
     *
     * This is a floor, not a description of any response shape: the spec
     * defines what an endpoint returns. Keeping it matters precisely because
     * this package is meant to live alongside legacy code — controllers, jobs
     * and exports that never pass through the spec at all, where nothing else
     * would stop a password hash from leaving.
     *
     * The rule of thumb: $hidden holds only what appears in no response, ever.
     * The moment it varies the shape between endpoints, it is doing the spec's
     * job and has become the problem.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
