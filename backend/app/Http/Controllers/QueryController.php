<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\OperationsRepository;
use App\Services\ClientScope;
use Illuminate\Http\Request;

class QueryController extends ApiController
{
    public function index(Request $r, OperationsRepository $repo, ClientScope $scope)
    {
        $map = ['usuarios' => User::class, 'clientes' => Customer::class, 'productos' => Product::class, 'cuentas' => Account::class, 'solicitudes' => CardApplication::class, 'creditos' => Credit::class, 'tarjetas' => Card::class, 'empresas' => Company::class, 'planillas' => Payroll::class, 'bitacora' => AuditEvent::class, 'seguridad' => SecurityEvent::class];
        $resource = $r->route()->defaults['resource'];
        $model = $map[$resource];
        $validated = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100', 'estado' => 'sometimes|string|max:50', 'cliente_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'solicitud_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'empresa_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'cliente_ref' => 'sometimes|string|max:100', 'dpi' => 'sometimes|string|regex:/^[0-9]{13}$/D']);
        $query = $repo->query($model);
        if ($resource === 'cuentas') {
            $query->whereNull('system_key');
        }
        if (in_array($resource, ['clientes', 'cuentas', 'solicitudes', 'tarjetas', 'creditos'], true)) {
            $cid = $scope->customerId();
            if ($cid) {
                $query->where($resource === 'clientes' ? '_id' : 'cliente_id', $cid);
            } elseif (isset($validated['cliente_id']) && $resource !== 'clientes') {
                $query->where('cliente_id', $validated['cliente_id']);
            }
        }
        if (in_array($resource, ['cuentas', 'solicitudes', 'tarjetas', 'creditos', 'planillas'], true) && isset($validated['estado'])) {
            $query->where('estado', $validated['estado']);
        }
        if ($resource === 'bitacora' && isset($validated['solicitud_id'])) {
            $query->where('solicitud_id', $validated['solicitud_id']);
        }
        if ($resource === 'planillas' && isset($validated['empresa_id'])) {
            $query->where('empresa_id', $validated['empresa_id']);
        }
        if ($r->user() && str_starts_with($r->user()->role, 'empresa_')) {
            $companies = Company::where('miembros', (string) $r->user()->id)->get()->pluck('id')->map(fn ($x) => (string) $x)->all();
            $query->whereIn($resource === 'empresas' ? '_id' : 'empresa_id', $companies);
        }

        return $this->ok($query->orderBy('created_at', 'desc')->paginate($validated['per_page'] ?? 25));
    }

    public function dashboard()
    {
        $recent = CardApplication::orderBy('created_at', 'desc')->limit(6)->get();

        return $this->ok(['solicitudes' => CardApplication::count(), 'pendientes' => CardApplication::whereIn('estado', ['en_evaluacion', 'revision_manual', 'aceptada', 'en_tramite'])->count(), 'emitidas' => CardApplication::where('estado', 'emitida')->count(), 'cuentas' => Account::whereNull('system_key')->count(), 'saldo_centavos' => (int) Account::whereNull('system_key')->sum('saldo_centavos'), 'planillas_pendientes' => Payroll::where('estado', 'pendiente_autorizacion')->count(), 'recientes' => $recent, 'por_estado' => collect(['en_evaluacion', 'revision_manual', 'aprobada', 'aceptada', 'en_tramite', 'emitida', 'rechazada'])->map(fn ($s) => ['estado' => $s, 'cantidad' => CardApplication::where('estado', $s)->count()])]);
    }

    public function riskPending()
    {
        $apps = CardApplication::where('estado', 'en_evaluacion')->paginate(50);
        $apps->getCollection()->transform(function ($a) {
            $c = Customer::find($a->cliente_id);
            $result = $a->toArray();
            $result['perfil_riesgo'] = $c?->only(['id', 'nombre', 'dpi', 'nit', 'ingreso_centavos', 'fecha_nacimiento', 'tipo_empleo', 'antiguedad_laboral_meses']);
            $result['saldo_cuentas_centavos'] = (int) Account::where('cliente_id', $a->cliente_id)->sum('saldo_centavos');

            return $result;
        });

        return $this->ok($apps);
    }

    public function integrations()
    {
        return $this->ok(['riesgos' => ['configurada' => (bool) config('operations.risk_url'), 'modo' => 'API con token de servicio; compatibilidad pendiente de contrato externo'], 'banca' => ['configurada' => (bool) config('operations.bank_url'), 'modo' => 'Webhook firmado con reintentos y consulta por API'], 'eventos_pendientes' => OutboxEvent::where('status', 'pendiente')->count(), 'eventos_fallidos' => OutboxEvent::where('status', 'fallido')->count(), 'eventos' => OutboxEvent::orderBy('created_at', 'desc')->limit(15)->get()]);
    }
}
