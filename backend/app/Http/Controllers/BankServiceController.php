<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\BankCustomerRequest;
use App\Http\Requests\BankServicePaymentRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use App\Services\ServicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankServiceController extends ApiController
{
    public function catalog(ServicePaymentService $service): JsonResponse
    {
        return $this->ok($service->catalog());
    }

    public function pay(BankServicePaymentRequest $request, ServicePaymentService $service): JsonResponse
    {
        return $this->mutation($request, fn (array $data, string $actor): array => $service->pay($data, $actor), 201);
    }

    public function history(Request $request, ServicePaymentService $service): JsonResponse
    {
        $data = $request->validate(BankCustomerRequest::identityRules() + ['page' => ['sometimes', 'integer', 'min:1', 'max:100000']], (new BankCustomerRequest)->messages());

        return $this->ok($service->history($data, (int) ($data['page'] ?? 1)));
    }

    /**
     * Lets Banca confirm a 10-digit account before saving a beneficiary or transferring.
     * Returns only a short holder name, never balances or identity data.
     */
    public function destination(Request $request, CustomerRepository $customers): JsonResponse
    {
        $data = $request->validate(BankCustomerRequest::identityRules() + ['numero' => ['required', 'string', 'regex:/^[0-9]{10}$/D']], (new BankCustomerRequest)->messages());
        $requester = $customers->resolve($data);
        $account = Account::where('numero', $data['numero'])->whereNull('system_key')->first();
        if (! $account || $account->estado !== 'activa') {
            throw new BusinessException('No existe una cuenta activa con ese número.', 'ACCOUNT_NOT_FOUND', 404);
        }
        $holder = Customer::find($account->cliente_id);
        $number = $account->numero;

        return $this->ok([
            'numero' => $number, 'numero_formateado' => substr($number, 0, 1).'-'.substr($number, 1, 3).'-'.substr($number, 4),
            'tipo' => $account->tipo, 'moneda' => $account->moneda, 'titular' => $this->shortName($holder),
            'propia' => $account->cliente_id === (string) $requester->id,
        ]);
    }

    private function shortName(?Customer $holder): ?string
    {
        if (! $holder) {
            return null;
        }
        $first = trim((string) ($holder->nombres ?: strtok((string) $holder->nombre, ' ')));
        $last = trim((string) ($holder->apellidos ?: preg_replace('/^\S+\s*/u', '', (string) $holder->nombre)));

        return trim(strtok($first, ' ').($last !== '' ? ' '.mb_substr($last, 0, 1).'.' : ''));
    }
}
