<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\OperationRequest;
use App\Models\AuditEvent;
use App\Models\CardApplication;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\ClientScope;

class ApplicationController extends ApiController
{
    public function store(OperationRequest $r, ApplicationService $s, ClientScope $scope)
    {
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

    public function show(string $id, ClientScope $scope, AuditService $audit)
    {
        $a = CardApplication::findOrFail($id);
        $scope->check($a->cliente_id);

        return $this->ok(['solicitud' => $a, 'historial' => AuditEvent::where('solicitud_id',$id)->orderBy('sequence')->get(), 'integridad_verificada' => $audit->verify($a)]);
    }
}
