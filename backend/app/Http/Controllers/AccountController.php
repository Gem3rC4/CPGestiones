<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Account;
use App\Models\Movement;
use App\Services\AccountService;
use App\Services\ClientScope;
use App\Services\SecurityService;
use App\Support\Money;
use Illuminate\Http\Request;

class AccountController extends ApiController
{
    public function store(OperationRequest $r, AccountService $s, ClientScope $scope)
    {
        return $this->mutation($r, function ($d, $a) use ($s, $scope) {
            $scope->check($d['cliente_id']);

            return $s->open($d, $a);
        }, 201);
    }

    public function transfer(OperationRequest $r, AccountService $s)
    {
        return $this->mutation($r, function ($d, $a) use ($s) {
            $s->ownership($d, $a);
            // Banca Virtual only knows the 10-digit number of third-party accounts.
            if (! isset($d['destino_id'])) {
                $target = Account::where('numero', $d['destino_numero'])->whereNull('system_key')->first();
                if (! $target) {
                    throw new BusinessException('No existe una cuenta con ese número.', 'ACCOUNT_NOT_FOUND', 404);
                }
                $d['destino_id'] = (string) $target->id;
            }
            foreach (['origen_id', 'destino_id'] as $f) {
                if (Account::findOrFail($d[$f])->system_key) {
                    throw new BusinessException('Usa la operación de caja para cuentas internas.', 'FORBIDDEN', 403);
                }
            }

            return $s->transfer($d, $a);
        }, 201);
    }

    public function cash(OperationRequest $r, AccountService $s)
    {
        return $this->mutation($r, function ($d, $a) use ($s) {
            $customer = Account::findOrFail($d['cuenta_id']);
            if ($customer->system_key) {
                throw new BusinessException('Selecciona una cuenta de cliente.', 'INVALID_ACCOUNT', 422);
            }

            // A deposit is money coming in: it is added to the vault (concentradora); a withdrawal leaves it.
            return $d['tipo'] === 'deposito'
                ? $s->cashIn((string) $customer->id, Money::cents($d['monto']), $a, $d['concepto'], 'deposito')
                : $s->cashOut((string) $customer->id, Money::cents($d['monto']), $a, $d['concepto'], 'retiro');
        }, 201);
    }

    public function state(OperationRequest $r, string $id)
    {
        return $this->mutation($r, function ($d, $a) use ($id) {
            $account = Account::findOrFail($id);
            if ($account->system_key) {
                throw new BusinessException('La cuenta interna no admite esta acción.', 'FORBIDDEN', 403);
            }
            $account->update(['estado' => $d['estado']]);
            app(SecurityService::class)->record('estado_cuenta', $a, ['cuenta_id' => $id, 'estado' => $d['estado'], 'nota' => $d['nota']]);

            return $account->toArray();
        });
    }

    /**
     * Opening receipt of a personal account, for bank staff (it shows the holder's full file).
     */
    public function receipt(Request $r, string $id, AccountService $s)
    {
        if (! $r->user()) {
            throw new BusinessException('El comprobante de apertura es exclusivo del personal del banco.', 'FORBIDDEN', 403);
        }
        $account = Account::whereNull('system_key')->whereNotNull('cliente_id')->findOrFail($id);

        return $this->ok($s->receipt($account));
    }

    public function movements(string $id, ClientScope $scope)
    {
        $account = Account::findOrFail($id);
        $scope->check($account->cliente_id ?? '');

        return $this->ok(Movement::where('cuenta_id', $id)->orderBy('created_at', 'desc')->paginate(50));
    }
}
