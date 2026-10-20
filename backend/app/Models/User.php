<?php

namespace App\Models;

class User extends Document
{
    protected $table = 'usuarios';

    protected $hidden = ['password', 'failed_attempts', 'locked_until'];

    protected $casts = ['active' => 'boolean', 'locked_until' => 'datetime'];
}
