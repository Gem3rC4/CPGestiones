<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\OperationAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AlertService
{
    public function publish(string $key, string $permission, string $title, string $message, ?string $resource = null, ?string $id = null): OperationAlert
    {
        return OperationAlert::firstOrCreate(['clave' => $key], ['permiso' => $permission, 'titulo' => $title, 'mensaje' => $message, 'recurso' => $resource, 'recurso_id' => $id, 'leida_por' => []]);
    }

    public function visible(Request $request): Builder
    {
        $permissions = $request->attributes->get('permissions', []);
        $allowed = array_values(array_intersect($permissions, ['clientes.read', 'cuentas.read', 'solicitudes.read']));
        if (! $request->user() || (! in_array('*', $permissions, true) && $allowed === [])) {
            throw new BusinessException('Las alertas son para el personal autorizado del banco.', 'FORBIDDEN', 403);
        }
        $query = OperationAlert::query();
        if (! in_array('*', $permissions, true)) {
            $query->whereIn('permiso', $allowed);
        }

        return $query;
    }

    public function feed(Request $request, int $page): array
    {
        $user = (string) $request->user()?->id;
        $query = $this->visible($request);
        $count = (clone $query)->where('leida_por', '!=', $user)->count();
        $items = $query->orderBy('_id', 'desc')->paginate(25, ['*'], 'page', $page);
        $items->getCollection()->transform(function (OperationAlert $alert) use ($user): array {
            return $alert->toArray() + ['leida' => in_array($user, $alert->leida_por ?? [], true)];
        });

        return ['sin_leer' => $count, 'alertas' => $items];
    }

    public function markRead(Request $request, array $ids): array
    {
        $query = $this->visible($request)->whereIn('_id', $ids);
        if ((clone $query)->count() !== count($ids)) {
            throw new BusinessException('No se encontraron las alertas para este usuario.', 'NOT_FOUND', 404);
        }
        $query->push('leida_por', (string) $request->user()->id, true);

        return ['marcadas' => count($ids)];
    }
}
