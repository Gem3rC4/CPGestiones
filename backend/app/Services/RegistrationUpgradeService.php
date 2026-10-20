<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegistrationUpgradeService
{
    public function run(): int
    {
        $seen = ['dpi' => [], 'email' => [], 'external_ref' => []];
        // Preflight finishes before any write. Run with API/workers stopped.
        foreach (Customer::cursor() as $customer) {
            $values = ['dpi' => Customer::fingerprint((string) $customer->dpi), 'email' => $customer->email ? mb_strtolower(trim($customer->email)) : null, 'external_ref' => $customer->external_ref];
            foreach ($values as $field => $value) {
                if ($value === null) {
                    continue;
                }
                if (isset($seen[$field][$value])) {
                    throw new RuntimeException('Existen clientes duplicados en '.$field.'. Revisión manual requerida; no se modificó ningún registro.');
                }
                $seen[$field][$value] = true;
            }
        }
        app(InstallService::class)->indexes();
        $count = 0;
        foreach (Customer::cursor() as $customer) {
            $updates = [];
            if (! $customer->getRawOriginal('dpi_hash')) {
                $updates['dpi'] = $customer->dpi;
            }
            if ($customer->email !== null && $customer->email !== mb_strtolower(trim($customer->email))) {
                $updates['email'] = $customer->email;
            }
            if ($updates !== []) {
                DB::connection('mongodb')->transaction(fn () => $customer->update($updates));
                $count++;
            }
        }

        return $count;
    }
}
