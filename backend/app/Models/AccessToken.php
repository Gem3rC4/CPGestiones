<?php

namespace App\Models;

class AccessToken extends Document
{
    protected $table = 'tokens';

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime'];
}
