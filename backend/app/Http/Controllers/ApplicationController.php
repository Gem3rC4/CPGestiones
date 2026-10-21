<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\AuditEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Credit;
use App\Models\Statement;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\ClientScope;
use App\Services\LoanService;

class ApplicationController extends ApiController
{
    public function store(OperationRequest $r, ApplicationService $s, ClientScope $scope)
    {
        // Gestiones originate exclusively in Banca Virtual; staff process them from the inbox.
        if ($r->attributes->get('actor') !== 'service:banca') {
            throw new BusinessException('Las gestiones se solicitan desde Banca Virtual. El personal las procesa desde el buzón de Gestiones.', 'BANK_ORIGIN_REQUIRED', 403);
        }

        return $this->mutation($r, function ($d, $a) use ($s, $scope) {
            $scope->check($d['cliente_id']);

            return $s->create($d, $a);
        }, 201);
    }

    public function risk(OperationRequest $r, string $id, ApplicationService $s)
    {
        return $this->mutation($r, fn ($d, $a) => $s->risk($id, $d, $a));
    }

    public function transition(OperationRequest $r, string $id, ApplicationService $s, ClientScope $scope)
    {
        return $this->mutation($r, function ($d, $a) use ($s, $id, $scope) {
            $scope->check(CardApplication::findOrFail($id)->cliente_id);
            $permissions = request()->attributes->get('permissions');
            if (! in_array('*', $permissions, true) && ! in_array('solicitudes.manage', $permissions, true) && ! in_array('solicitudes.accept', $permissions, true)) {
                throw new BusinessException('No tienes permiso para cambiar el estado.', 'FORBIDDEN', 403);
            }

            return $s->transition($id, $d, $a);
        });
    }

    /**
     * Expediente: the gestión, its signed history and the product it produced (loan schedule or card with statements).
     */
    public function show(string $id, ClientScope $scope, AuditService $audit, LoanService $loans)
    {
        $a = CardApplication::findOrFail($id);
        $scope->check($a->cliente_id);
        $credit = $a->credito_id ? Credit::find($a->credito_id) : null;
        $card = $a->tarjeta_id ? Card::find($a->tarjeta_id) : null;

        return $this->ok(['solicitud' => $a, 'historial' => AuditEvent::where('solicitud_id', $id)->orderBy('sequence')->get(), 'integridad_verificada' => $audit->verify($a),
            'credito' => $credit ? $loans->view($credit) : null,
            'tarjeta' => $card ? $card->toArray() + ['estados_cuenta' => Statement::where('tarjeta_id', (string) $card->id)->orderBy('fecha_corte', 'desc')->limit(12)->get()] : null]);
    }
}
