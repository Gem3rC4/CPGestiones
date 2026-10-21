<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Models\Credit;
use App\Services\ClientScope;
use App\Services\CollectionService;
use App\Services\LoanService;
use App\Services\SecurityService;
use Illuminate\Http\Request;

class CreditController extends ApiController
{
    /**
     * Loan with its installment schedule, outstanding balance and next payment (expediente and Banca Virtual).
     */
    public function show(string $id, ClientScope $scope, LoanService $loans)
    {
        $credit = Credit::findOrFail($id);
        $scope->check($credit->cliente_id);

        return $this->ok($loans->view($credit));
    }

    /**
     * Pays installments from one of the borrower's accounts. Without "monto" it pays the next installment.
     */
    public function pay(OperationRequest $r, string $id, LoanService $loans)
    {
        return $this->mutation($r, fn ($d, $a) => $loans->pay($id, $d, $a), 201);
    }

    /**
     * Runs the automatic collections now. Each item has its own transaction and is idempotent by state,
     * so running it twice never charges twice.
     */
    public function collect(Request $r, CollectionService $collections)
    {
        $result = $collections->run();
        app(SecurityService::class)->record('cobros_ejecutados', $r->attributes->get('actor'), ['prestamos' => count($result['prestamos']), 'tarjetas' => count($result['tarjetas'])]);

        return $this->ok($result);
    }
}
