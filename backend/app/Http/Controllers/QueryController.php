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
use App\Models\Statement;
use App\Models\User;
use App\Repositories\OperationsRepository;
use App\Services\CardService;
use App\Services\ClientScope;
use App\Services\ExchangeRateService;
use App\Services\IntegrationSettings;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class QueryController extends ApiController
{
    private const MODELS = ['usuarios' => User::class, 'clientes' => Customer::class, 'productos' => Product::class, 'cuentas' => Account::class, 'solicitudes' => CardApplication::class, 'creditos' => Credit::class, 'tarjetas' => Card::class, 'empresas' => Company::class, 'planillas' => Payroll::class, 'bitacora' => AuditEvent::class, 'seguridad' => SecurityEvent::class, 'movimientos' => Movement::class, 'colaboradores' => Collaborator::class];

    /**
     * Text search (q) looks in these fields; resources with a customer also match the customer's name.
     */
    private const SEARCH = ['usuarios' => ['name', 'email', 'role'], 'clientes' => ['nombre', 'email', 'nit', 'telefono'], 'productos' => ['name', 'code', 'type'], 'cuentas' => ['numero', 'nombre', 'tipo'],
        'solicitudes' => ['folio', 'producto', 'gestion_ref'], 'creditos' => ['referencia', 'producto'], 'tarjetas' => ['referencia', 'ultimos_cuatro', 'producto'], 'empresas' => ['nombre', 'razon_social', 'nit', 'representante_legal'],
        'planillas' => ['nombre', 'empresa_nombre'], 'bitacora' => ['action', 'note', 'actor'], 'seguridad' => ['action', 'actor', 'ip'], 'movimientos' => ['concepto', 'tipo'], 'colaboradores' => ['nombre', 'puesto', 'cuenta_numero', 'empresa_nombre']];

    /**
     * Columns each listing can be ordered by (orden=field&direccion=asc|desc).
     */
    private const SORT = ['usuarios' => ['name', 'email', 'role', 'created_at'], 'clientes' => ['nombre', 'email', 'ingreso_centavos', 'created_at'], 'productos' => ['name', 'type', 'annual_bps', 'max_limit_cents'],
        'cuentas' => ['numero', 'tipo', 'saldo_centavos', 'estado', 'created_at'], 'solicitudes' => ['folio', 'producto', 'limite_solicitado_centavos', 'estado', 'created_at'],
        'creditos' => ['referencia', 'producto', 'limite_centavos', 'saldo_centavos', 'estado', 'created_at'], 'tarjetas' => ['referencia', 'producto', 'limite_centavos', 'principal_centavos', 'estado', 'created_at'],
        'empresas' => ['nombre', 'nit', 'created_at'], 'planillas' => ['nombre', 'total_centavos', 'estado', 'created_at'], 'bitacora' => ['action', 'estado', 'created_at'], 'seguridad' => ['action', 'created_at'],
        'movimientos' => ['created_at', 'tipo', 'importe_centavos'], 'colaboradores' => ['nombre', 'puesto', 'salario_centavos', 'created_at']];

    public function index(Request $r, OperationsRepository $repo, ClientScope $scope)
    {
        $resource = $r->route()->defaults['resource'];
        $validated = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'per_page' => 'sometimes|integer|min:1|max:100', 'estado' => 'sometimes|nullable|string|max:50', 'cliente_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'solicitud_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'empresa_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'cuenta_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'puesto' => 'sometimes|string|max:80', 'cliente_ref' => 'sometimes|string|max:100', 'dpi' => 'sometimes|string|regex:/^[0-9]{13}$/D',
            'q' => 'sometimes|nullable|string|max:100', 'orden' => 'sometimes|nullable|in:'.implode(',', self::SORT[$resource]), 'direccion' => 'sometimes|nullable|in:asc,desc', 'desde' => 'sometimes|nullable|date_format:Y-m-d', 'hasta' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:desde'],
            ['orden.in' => 'No se puede ordenar por esa columna.', 'date_format' => 'Usa una fecha válida.', 'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);
        // The global ledger is for bank staff only; integrations read movements per account.
        if ($resource === 'movimientos' && ! $r->user()) {
            throw new BusinessException('El registro global de movimientos es exclusivo del personal del banco.', 'FORBIDDEN', 403);
        }
        $query = $repo->query(self::MODELS[$resource]);
        // Personal and corporate banking never mix: company accounts, company users and company-holder
        // customers only appear when a company is asked for explicitly (empresa_id).
        $companyScope = $validated['empresa_id'] ?? null;
        if ($resource === 'cuentas') {
            $query->whereNull('system_key');
            $companyScope ? $query->where('empresa_id', $companyScope) : $query->whereNull('empresa_id');
        }
        if ($resource === 'clientes') {
            $query->where('segmento', '!=', 'empresa');
        }
        if ($resource === 'usuarios') {
            $companyScope ? $query->where('role', 'empresa')->where('empresa_id', $companyScope) : $query->where('role', '!=', 'empresa');
        }
        if (in_array($resource, ['clientes', 'cuentas', 'solicitudes', 'tarjetas', 'creditos'], true)) {
            $cid = $scope->customerId();
            if ($cid) {
                $query->where($resource === 'clientes' ? '_id' : 'cliente_id', $cid);
            } elseif (isset($validated['cliente_id']) && $resource !== 'clientes') {
                $query->where('cliente_id', $validated['cliente_id']);
            }
        }
        if (! empty($validated['estado'])) {
            // In the ledger the status filter selects the movement type (deposito, transferencia, planilla…).
            $field = ['movimientos' => 'tipo', 'usuarios' => 'role', 'productos' => 'type', 'clientes' => 'origen'][$resource] ?? 'estado';
            $query->where($field, $validated['estado']);
        }
        if ($resource === 'movimientos' && isset($validated['cuenta_id'])) {
            $query->where('cuenta_id', $validated['cuenta_id']);
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
        if ($r->user()?->role === 'empresa') {
            $query->where($resource === 'empresas' ? '_id' : 'empresa_id', (string) $r->user()->empresa_id);
        }
        $zone = config('operations.timezone');
        if (! empty($validated['desde'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $validated['desde'], $zone)->utc());
        }
        if (! empty($validated['hasta'])) {
            $query->where('created_at', '<=', CarbonImmutable::createFromFormat('!Y-m-d', $validated['hasta'], $zone)->endOfDay()->utc());
        }
        if (! empty($validated['q'])) {
            $this->search($query, $resource, trim($validated['q']));
        }
        $page = $query->orderBy($validated['orden'] ?? 'created_at', $validated['direccion'] ?? 'desc')->paginate($validated['per_page'] ?? 25);
        if ($resource === 'movimientos') {
            $this->withAccountNumbers($page);
        }
        if ($resource === 'empresas') {
            $this->withCompanyBalances($page);
        }
        if ($resource === 'tarjetas') {
            $cards = app(CardService::class);
            $page->setCollection($page->getCollection()->map(fn (Card $card): array => $card->toArray() + ($card->tipo === 'credito' ? ['ciclo' => $cards->cycle($card)] : [])));
        }
        if ($resource === 'creditos') {
            $loans = app(LoanService::class);
            $page->setCollection($page->getCollection()->map(fn (Credit $credit): array => $loans->view($credit)));
        }

        return $this->ok($page);
    }

    /**
     * Case-insensitive search; the term is escaped so it is matched literally. A 13-digit term also finds a
     * customer by DPI (stored encrypted, looked up by its fingerprint).
     */
    private function search($query, string $resource, string $term): void
    {
        $pattern = '/'.preg_quote($term, '/').'/i';
        $customers = in_array($resource, ['cuentas', 'solicitudes', 'creditos', 'tarjetas'], true)
            ? Customer::where('nombre', 'regex', $pattern)->limit(200)->get()->map(fn ($c) => (string) $c->id)->all() : [];
        $accounts = $resource === 'movimientos' ? $this->accountsMatching($pattern) : [];
        $query->where(function ($q) use ($resource, $pattern, $customers, $accounts, $term) {
            foreach (self::SEARCH[$resource] as $field) {
                $q->orWhere($field, 'regex', $pattern);
            }
            if ($customers !== []) {
                $q->orWhereIn('cliente_id', $customers);
            }
            if ($accounts !== []) {
                $q->orWhereIn('cuenta_id', $accounts);
            }
            if ($resource === 'clientes' && preg_match('/^[0-9]{13}$/D', $term)) {
                $q->orWhere('dpi_hash', Customer::fingerprint($term));
            }
        });
    }

    private function accountsMatching(string $pattern): array
    {
        $owners = Customer::where('nombre', 'regex', $pattern)->limit(200)->get()->map(fn ($c) => (string) $c->id)->all();

        return Account::where(fn ($q) => $q->where('numero', 'regex', $pattern)->orWhereIn('cliente_id', $owners))->limit(500)->get()->map(fn ($a) => (string) $a->id)->all();
    }

    private function withCompanyBalances($page): void
    {
        $ids = $page->getCollection()->map(fn (Company $c) => (string) $c->id)->all();
        $accounts = Account::whereIn('empresa_id', $ids)->get()->groupBy('empresa_id');
        $users = User::where('role', 'empresa')->whereIn('empresa_id', $ids)->get()->groupBy('empresa_id');
        $page->setCollection($page->getCollection()->map(fn (Company $c): array => $c->toArray() + [
            'saldo_total_centavos' => (int) ($accounts[(string) $c->id] ?? collect())->sum('saldo_centavos'),
            'cuentas_total' => ($accounts[(string) $c->id] ?? collect())->count(),
            'representantes_total' => ($users[(string) $c->id] ?? collect())->count(),
        ]));
    }

    private function withAccountNumbers($page): void
    {
        $accounts = Account::whereIn('_id', $page->getCollection()->pluck('cuenta_id')->filter()->unique()->values()->all())->get()->keyBy(fn (Account $a) => (string) $a->id);
        $names = Customer::whereIn('_id', $accounts->pluck('cliente_id')->filter()->unique()->values()->all())->get()->keyBy(fn (Customer $c) => (string) $c->id);
        $companies = Company::whereIn('_id', $accounts->pluck('empresa_id')->filter()->unique()->values()->all())->get()->keyBy(fn (Company $c) => (string) $c->id);
        $page->setCollection($page->getCollection()->map(function (Movement $movement) use ($accounts, $names, $companies): array {
            $account = $accounts->get((string) $movement->cuenta_id);
            $company = $account?->empresa_id ? $companies->get((string) $account->empresa_id) : null;

            return $movement->toArray() + ['cuenta_numero' => $account?->numero, 'cuenta_interna' => (bool) $account?->system_key, 'cuenta_nombre' => $account?->system_key ? $account->nombre : null, 'cliente_id' => $account?->cliente_id, 'empresa_id' => $account?->empresa_id,
                'cliente_nombre' => $company ? $company->nombre.' (empresa)' : $names->get((string) $account?->cliente_id)?->nombre];
        }));
    }

    public function dashboard()
    {
        $recent = CardApplication::orderBy('created_at', 'desc')->limit(6)->get();
        $customerAccounts = fn () => Account::whereNull('system_key')->whereNull('empresa_id');
        $creditCards = fn () => Card::where('tipo', 'credito');
        $todayStart = now()->timezone(config('operations.timezone'))->startOfDay()->utc();
        $today = now()->timezone(config('operations.timezone'))->toDateString();
        $loans = Credit::where('estado', 'vigente')->get();
        $overdue = $loans->sum(fn (Credit $c): int => collect($c->cuotas ?? [])->filter(fn (array $q): bool => $q['estado'] !== 'pagada' && $q['fecha_vencimiento'] < $today)->count());
        $master = Account::where('system_key', 'concentradora')->first();

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
            'concentradora' => $master ? $this->masterSummary($master) : null,
            // Centralized registry: totals Gestiones administers as the core of the three systems.
            'global' => [
                'clientes' => Customer::where('segmento', '!=', 'empresa')->count(),
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
                'estados_por_cobrar' => Statement::where('fecha_pago', '>=', $today)->count(),
                'creditos_autorizados' => Credit::count(),
                'creditos_autorizados_centavos' => (int) Credit::sum('limite_centavos'),
                'prestamos_vigentes' => $loans->count(),
                'cartera_prestamos_centavos' => (int) $loans->sum('saldo_centavos'),
                'cuotas_vencidas' => $overdue,
                'movimientos_hoy' => Movement::where('created_at', '>=', $todayStart)->count(),
                'volumen_hoy_centavos' => (int) Movement::where('created_at', '>=', $todayStart)->where('importe_centavos', '>', 0)->sum('importe_centavos'),
                'pagos_servicios' => ServicePayment::count(),
                'pagos_servicios_centavos' => (int) ServicePayment::sum('monto_centavos'),
                'empresas' => Company::count(),
                'cuentas_empresariales' => Account::whereNull('system_key')->whereNotNull('empresa_id')->count(),
                'saldo_empresas_centavos' => (int) Account::whereNull('system_key')->whereNotNull('empresa_id')->sum('saldo_centavos'),
                'colaboradores' => Collaborator::count(),
            ],
        ]);
    }

    private function masterSummary(Account $master): array
    {
        $byType = Movement::raw(fn ($c) => $c->aggregate([
            ['$match' => ['cuenta_id' => (string) $master->id, 'tipo' => ['$ne' => 'apertura_fondos']]],
            ['$group' => ['_id' => '$tipo', 'salidas' => ['$sum' => ['$cond' => [['$lt' => ['$importe_centavos', 0]], ['$multiply' => ['$importe_centavos', -1]], 0]]], 'entradas' => ['$sum' => ['$cond' => [['$gt' => ['$importe_centavos', 0]], '$importe_centavos', 0]]]]],
        ]));
        $rows = collect($byType)->map(fn ($r): array => ['tipo' => $r['_id'], 'salidas_centavos' => (int) $r['salidas'], 'entradas_centavos' => (int) $r['entradas']])->sortByDesc('salidas_centavos')->values();

        return ['numero' => $master->numero, 'nombre' => $master->nombre, 'saldo_centavos' => (int) $master->saldo_centavos,
            'fondos_iniciales_centavos' => (int) Movement::where('cuenta_id', (string) $master->id)->where('tipo', 'apertura_fondos')->sum('importe_centavos'),
            'salidas_centavos' => (int) $rows->sum('salidas_centavos'), 'entradas_centavos' => (int) $rows->sum('entradas_centavos'), 'por_tipo' => $rows,
            'desembolsado_centavos' => (int) Credit::sum('desembolsado_centavos')];
    }

    public function exchangeRate(ExchangeRateService $rates)
    {
        return $this->ok($rates->current());
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
