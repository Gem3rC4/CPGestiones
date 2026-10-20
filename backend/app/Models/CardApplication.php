<?php

namespace App\Models;

class CardApplication extends Document
{
    protected $table = 'solicitudes';

    protected $hidden = ['recepcion_hash'];
}
