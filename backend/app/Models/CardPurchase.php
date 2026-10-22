<?php

namespace App\Models;

/**
 * Every purchase attempt with a card, approved or rejected: the central log of card transactions.
 */
class CardPurchase extends Document
{
    protected $table = 'compras_tarjeta';
}
