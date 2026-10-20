<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

abstract class Document extends Model
{
    protected $connection = 'mongodb';

    protected $guarded = ['_id', 'id'];
}
