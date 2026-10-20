<?php

namespace App\Models;

class BankEvent extends Document
{
    protected $table = 'eventos_entrada';

    protected $hidden = ['fingerprint'];
}
