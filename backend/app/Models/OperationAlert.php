<?php

namespace App\Models;

class OperationAlert extends Document
{
    protected $table = 'alertas';

    protected $hidden = ['leida_por'];
}
