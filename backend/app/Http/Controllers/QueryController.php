<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Card;
use App\Models\CardApplication;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\ServicePayment;
use App\Models\User;
use App\Repositories\OperationsRepository;
use App\Services\ClientScope;
use App\Services\IntegrationSettings;
use Illuminate\Http\Request;

class QueryController extends ApiController
{
    public function index(Request $r, OperationsRepository $repo, ClientScope $scope)
    {
        $map = ['usuarios' => User::class, 'clientes' => Customer::class, 'productos' => Product::class, 'cuentas' => Account::class, 'solicitudes' => CardApplication::class, 'creditos' => Credit::class, 'tarjetas' => Card::class, 'empresas' => Company::class, 'planillas' => Payroll::class, 'bitacora' => AuditEvent::class, 'seguridad' => SecurityEvent::class, 'movimientos' => Movement::class, 'colaboradores' => Collaborator::class];
        $resource = $r->route()->defaults['resource'];
        $model = $map[$resource];
        $validated = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100', 'estado' => 'sometimes|string|max:50', 'cliente_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'solicitud_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'empresa_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'cuenta_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'puesto' => 'sometimes|string|max:80', 'cliente_ref' => 'sometimes|string|max:100', 'dpi' => 'sometimes|string|regex:/^[0-9]{13}$/D']);
        // The global ledger is for bank staff only; integrations read movements per account.
        if ($resource === 'movimientos' && ! $r->user()) {
            throw new BusinessException('El registro global de movimientos es exclusivo del personal del banco.', 'FORBIDDEN', 403);
        }
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
        if ($resource === 'movimientos') {
            // In the ledger the status filter selects the movement type (deposito, transferencia, planilla…).
            if (isset($validated['estado'])) {
                $query->where('tipo', $validated['estado']);
            }
            if (isset($validated['cuenta_id'])) {
                $query->where('cuenta_id', $validated['cuenta_id']);
            }
        }
        if ($resource === 'colaboradores' && isset($validated['puesto'])) {
            $query->where('puesto', $validated['puesto']);
        }
        if ($resource === 'bitacora' && isset($validated['solicitud_id'])) {
            $query->where('solicitud_id', $validated['solicitud_id']);
        }
        if (in_array($resource, ['planillas', 'colaboradores'], true) && isset($validated['empresa_id'])) {
            $query->where('empresa_id', $validated['empresa_id']);
        }
        if ($r->user() && str_starts_with($r->user()->role, 'empresa_')) {
            $companies = Company::where('miembros', (string) $r->user()->id)->get()->pluck('id')->map(fn ($x) => (string) $x)->all();
            $query->whereIn($resource === 'empresas' ? '_id' : 'empresa_id', $companies);
        }
        $page = $query->orderBy('created_at', 'desc')->paginate($validated['per_page'] ?? 25);
        if ($resource === 'movimientos') {
            $this->withAccountNumbers($page);
        }

        return $this->ok($page);
    }

    private function withAccountNumbers($page): void
    {
        $accounts = Account::whereIn('_id', $page->getCollection()->pluck('cuenta_id')->filter()->unique()->values()->all())->get()->keyBy(fn (Account $a) => (string) $a->id);
        $page->setCollection($page->getCollection()->map(function (Movement $movement) use ($accounts): array {
            $account = $accounts[$movement->cuenta_id] ?? null;

            return $movement->toArray() + ['cuenta_numero' => $account?->numero, 'cuenta_interna' => (bool) $account?->system_key, 'cliente_id' => $account?->cliente_id];
        }));
    }

    public function dashboard()
    {
        $recent = CardApplication::orderBy('created_at', 'desc')->limit(6)->get();
        $customerAccounts = fn () => Account::whereNull('system_key');
        $creditCards = fn () => Card::where('tipo', 'credito');
        $todayStart = now()->timezone(config('operations.timezone'))->startOfDay()->utc();

        return $this->ok([
            'solicitudes' => CardApplication::count(),
            'pendientes' => CardApplication::where('estado', 'en_evaluacion')->count(),
            'emitidas' => CardApplication::where('estado', 'emitida')->count(),
            'cuentas' => $customerAccounts()->count(),
            'saldo_centavos' => (int) $customerAccounts()->sum('saldo_centavos'),
            'planillas_pendientes' => Payroll::where('estado', 'pendiente_autorizacion')->count(),
            'recientes' => $recent,
            // Gestiones arrive approved by CP Analítica: the inbox only has these two stages.
            'por_estado' => collect(['en_evaluacion', 'emitida'])->map(fn ($s) => ['estado' => $s, 'cantidad' => CardApplication::where('estado', $s)->count()]),
            // Centralized registry: totals Gestiones administers as the core of the three systems.
            'global' => [
                'clientes' => Customer::count(),
                'clientes_con_app' => Customer::whereNotNull('password')->count(),
                'sesiones_app_activas' => CustomerSession::where('expires_at', '>', now())->count(),
                'cuentas_ahorro' => $customerAccounts()->where('tipo', 'ahorro')->count(),
                'cuentas_monetarias' => $customerAccounts()->where('tipo', 'monetaria')->count(),
                'cuentas_bloqueadas' => $customerAccounts()->where('estado', 'bloqueada')->count(),
                'saldo_ahorro_centavos' => (int) $customerAccounts()->where('tipo', 'ahorro')->sum('saldo_centavos'),
                'saldo_monetario_centavos' => (int) $customerAccounts()->where('tipo', 'monetaria')->sum('saldo_centavos'),
                'tarjetas_credito' => $creditCards()->count(),
                'tarjetas_debito' => Card::where('tipo', 'debito')->count(),
                'limite_credito_centavos' => (int) $creditCards()->sum('limite_centavos'),
                'deuda_tarjetas_centavos' => (int) $creditCards()->sum('principal_centavos') + (int) $creditCards()->sum('interes_centavos'),
                'creditos_autorizados' => Credit::count(),
                'creditos_autorizados_centavos' => (int) Credit::sum('limite_centavos'),
                'movimientos_hoy' => Movement::where('created_at', '>=', $todayStart)->count(),
                'volumen_hoy_centavos' => (int) Movement::where('created_at', '>=', $todayStart)->where('importe_centavos', '>', 0)->sum('importe_centavos'),
                'pagos_servicios' => ServicePayment::count(),
                'pagos_servicios_centavos' => (int) ServicePayment::sum('monto_centavos'),
                'empresas' => Company::count(),
                'colaboradores' => Collaborator::count(),
            ],
        ]);
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

    public function integrations(IntegrationSettings $settings)
    {
        return $this->ok(['riesgos' => ['configurada' => $settings->get('riesgos')['url'] !== null, 'modo' => 'API con token de servicio'], 'banca' => ['configurada' => $settings->get('banca')['url'] !== null, 'modo' => 'Webhook firmado con reintentos y consulta por API'], 'eventos_pendientes' => OutboxEvent::where('status', 'pendiente')->count(), 'eventos_fallidos' => OutboxEvent::where('status', 'fallido')->count(), 'eventos' => OutboxEvent::orderBy('created_at', 'desc')->limit(15)->get()]);
    }
}
