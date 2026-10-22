<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\Card;
use App\Models\CardPurchase;
use App\Models\Statement;
use App\Services\CardService;
use App\Services\ClientScope;
use App\Services\IdempotencyService;
use App\Services\SecurityService;
use Illuminate\Http\Request;

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

    /**
     * 201 with the approval, or 422 with a clear code (LIMITE_EXCEDIDO, FONDOS_INSUFICIENTES, TARJETA_BLOQUEADA).
     * Either way the attempt is in the purchase log; a retry with the same Idempotency-Key replays the answer.
     */
    public function authorizePurchase(OperationRequest $r, string $id, CardService $s)
    {
        $actor = $r->attributes->get('actor');
        $data = $r->validated();
        $result = app(IdempotencyService::class)->run($actor, $r->header('Idempotency-Key', ''), $r->method().':'.$r->path(), $data, fn (): array => $s->authorizePurchase($id, $data, $actor));
        if ($result['estado'] === 'aprobada') {
            return $this->ok($result, 201);
        }

        return response()->json(['ok' => false, 'message' => $result['motivo'], 'code' => $result['codigo'], 'data' => $result], 422);
    }

    public function purchases(string $id, ClientScope $scope, CardService $s)
    {
        $card = Card::findOrFail($id);
        $scope->check($card->cliente_id);
        $page = CardPurchase::where('tarjeta_id', $id)->orderBy('created_at', 'desc')->paginate(50);
        $page->setCollection($page->getCollection()->map(fn (CardPurchase $p): array => $s->purchaseView($p)));

        return $this->ok($page);
    }

    /**
     * Central log of every card purchase attempt, for bank staff.
     */
    public function allPurchases(Request $r, CardService $s)
    {
        if (! $r->user()) {
            throw new BusinessException('El registro global de compras es exclusivo del personal del banco.', 'FORBIDDEN', 403);
        }
        $f = $r->validate(['estado' => 'sometimes|nullable|in:aprobada,rechazada', 'page' => 'sometimes|integer|min:1|max:100000']);
        $page = CardPurchase::when($f['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))->orderBy('created_at', 'desc')->paginate(50);
        $page->setCollection($page->getCollection()->map(fn (CardPurchase $p): array => $s->purchaseView($p)));

        return $this->ok($page);
    }

    public function cycle(string $id, ClientScope $scope, CardService $s)
    {
        $card = Card::findOrFail($id);
        $scope->check($card->cliente_id);
        if ($card->tipo !== 'credito') {
            throw new BusinessException('Solo las tarjetas de crédito tienen fecha de corte y de pago.', 'INVALID_CARD', 422);
        }

        return $this->ok($s->cycle($card));
    }

    /**
     * GET /banca/tarjetas/ciclos?dpi=… : cut-off and payment due dates of every credit card of the customer.
     */
    public function cycles(ClientScope $scope, CardService $s)
    {
        $customer = $scope->customerId();
        if ($customer === null) {
            throw new BusinessException('Indica el DPI del cliente.', 'CLIENT_SCOPE_REQUIRED', 422);
        }

        return $this->ok(Card::where('cliente_id', $customer)->where('tipo', 'credito')->orderBy('created_at')->get()->map(fn (Card $c): array => $s->cycle($c))->values());
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
