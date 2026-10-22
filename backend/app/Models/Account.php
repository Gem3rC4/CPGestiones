<?php

namespace App\Models;

use LogicException;

class Account extends Document
{
    protected $table = 'cuentas';

    /**
     * The account number is assigned once, when the account is opened, and Banca Virtual registers the
     * same number: it can never change afterwards.
     */
    protected static function booted(): void
    {
        static::updating(function (Account $account): void {
            if ($account->isDirty('numero') && $account->getOriginal('numero') !== null) {
                throw new LogicException('El número de cuenta no se puede modificar.');
            }
        });
    }
}
