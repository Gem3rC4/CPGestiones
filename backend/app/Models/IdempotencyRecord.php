<?php

namespace App\Models;

class IdempotencyRecord extends Document
{
    protected $table = 'idempotencias';
}
