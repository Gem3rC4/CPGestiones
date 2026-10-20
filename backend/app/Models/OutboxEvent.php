<?php

namespace App\Models;

class OutboxEvent extends Document
{
    protected $table = 'eventos_salida';

    protected $hidden = ['payload'];
}
