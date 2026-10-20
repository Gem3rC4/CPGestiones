<?php

namespace App\Models;

class AuditEvent extends Document
{
    protected $table = 'bitacora';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La bitácora no admite edición.'));
        static::deleting(fn () => throw new \LogicException('La bitácora no admite eliminación.'));
    }
}
