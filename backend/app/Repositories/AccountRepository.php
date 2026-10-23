<?php

namespace App\Repositories;

use App\Models\Account;
use App\Support\Money;

class AccountRepository
{
    public function find(string $id): Account
    {
        return Account::findOrFail($id);
    }

    public function create(array $data): Account
    {
        return Account::create($data);
    }

    public function debit(string $id, int $cents, bool $allowNegative = false): bool
    {
        // Counterparty accounts (the bank's capital, cash…) may go negative down to -LEDGER_MAX; every other account needs funds.
        $floor = $allowNegative ? $cents - Money::LEDGER_MAX : $cents;

        return Account::where('_id', $id)->where('saldo_centavos', '>=', $floor)->decrement('saldo_centavos', $cents) === 1;
    }

    public function credit(string $id, int $cents, bool $internal = false): bool
    {
        // Customer and company accounts hold up to MAX; the bank's internal accounts (the vault) up to LEDGER_MAX.
        return Account::where('_id', $id)->where('saldo_centavos', '<=', ($internal ? Money::LEDGER_MAX : Money::MAX) - $cents)->increment('saldo_centavos', $cents) === 1;
    }
}
