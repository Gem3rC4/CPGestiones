<?php

namespace App\Models;

class CustomerSession extends Document
{
    protected $table = 'sesiones_clientes';

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'ultima_actividad' => 'datetime'];
}
