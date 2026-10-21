<?php

namespace App\Models;

/**
 * Staff panel session: holds the hash of the current refresh token. Access JWTs carry its id (sid),
 * so closing the session revokes them immediately.
 */
class UserSession extends Document
{
    protected $table = 'sesiones_usuarios';

    protected $hidden = ['refresh_hash', 'refresh_prev_hash'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
