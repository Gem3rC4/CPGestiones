<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationRequest;
use App\Models\Card;
use App\Models\Statement;
use App\Services\CardService;
use App\Services\ClientScope;
use App\Services\SecurityService;

class CardController extends ApiController
{
    public function charge(OperationRequest $r, string $id, CardService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->charge($id, $d, $a), 201);
    }

    public function pay(OperationRequest $r, string $id, CardService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->pay($id, $d, $a), 201);
    }

    public function statements(string $id, ClientScope $scope)
    {
        $card = Card::findOrFail($id);
        $scope->check($card->cliente_id);

        return $this->ok(Statement::where('tarjeta_id', $id)->orderBy('fecha_corte', 'desc')->paginate(25));
    }

    public function state(OperationRequest $r, string $id, ClientScope $scope)
    {
        return $this->mutation($r, function ($d, $a) use ($id, $scope) {
            $c = Card::findOrFail($id);
            $scope->check($c->cliente_id);
            $c->update(['estado' => $d['estado']]);
            app(SecurityService::class)->record('estado_tarjeta', $a, ['tarjeta_id' => $id, 'estado' => $d['estado'], 'nota' => $d['nota']]);

            return $c->toArray();
        });
    }
}
