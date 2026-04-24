<?php

namespace NexarGraphQL\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NexarToken extends Model
{
    use HasFactory;

    protected $table = 'nexar_tokens';

    protected $fillable = [
        'client_id',
        'client_secret',
        'organization_id',
        'supply_token',
        'scope',
        'expires_at',
        'expires_in',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'expires_in' => 'integer',
        'organization_id' => 'integer',
    ];

    /**
     * Avoid leaking the raw client secret in array/JSON responses.
     */
    protected $hidden = [
        'client_secret',
        'supply_token',
    ];
}
