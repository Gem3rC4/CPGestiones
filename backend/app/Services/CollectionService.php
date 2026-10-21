<?php

namespace App\Services;

/**
 * Automatic collections: loan installments due and credit card statements whose payment day arrived.
 * Scheduled daily (18:00 Guatemala) and available to the administrator on demand.
 */
class CollectionService
{
    public function __construct(private LoanService $loans, private CardService $cards) {}

    public function run(?string $date = null): array
    {
        $date ??= now()->timezone(config('operations.timezone'))->toDateString();

        return ['fecha' => $date, 'prestamos' => $this->loans->collectDue($date), 'tarjetas' => $this->cards->collectStatements($date)];
    }
}
