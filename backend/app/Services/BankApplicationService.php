<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Account;
use App\Models\CardApplication;
use App\Models\Product;
use App\Repositories\CustomerRepository;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class BankApplicationService
{
    public function __construct(private CustomerRepository $customers, private BankRegistrationService $profiles, private ApplicationService $applications, private SecurityService $security) {}

    /**
     * Banca Virtual sends the gestión once CP Analítica approved it and the customer accepted, so it lands in
     * the inbox as en_evaluacion waiting only for the bank's final approval. The controller's IdempotencyService
     * holds the transaction, so the profile update, the gestión, its audit trail and the alert commit together.
     */
    public function request(array $data, string $actor): array
    {
        $customer = $this->customers->resolve($data);
        $product = Product::where('code', $data['producto'])->where('active', true)->first();
        if (! $product || ! in_array($product->type, ['credito', 'prestamo', 'debito'], true)) {
            throw ValidationException::withMessages(['producto' => 'Selecciona una tarjeta o un crédito disponible del catálogo.']);
        }
        if (isset($data['fecha_nacimiento']) && $customer->fecha_nacimiento && $customer->fecha_nacimiento !== $data['fecha_nacimiento']) {
            throw ValidationException::withMessages(['fecha_nacimiento' => 'La fecha de nacimiento no coincide con la registrada. Acude a una agencia para corregirla.']);
        }
        $updates = $this->profiles->profileData($data) + (isset($data['fecha_nacimiento']) && ! $customer->fecha_nacimiento ? ['fecha_nacimiento' => $data['fecha_nacimiento']] : []);
        $changed = array_filter($updates, fn ($value, string $field): bool => $customer->{$field} !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $customer = $this->customers->update($customer, $changed + ['perfil_version' => (int) $customer->perfil_version + 1]);
            $this->security->record('perfil_completado_en_gestion', $actor, ['cliente_id' => (string) $customer->id, 'version' => $customer->perfil_version]);
        }
        if ($customer->fecha_nacimiento && $customer->antiguedad_laboral_meses !== null) {
            $this->profiles->assertSeniority($customer->fecha_nacimiento, (int) $customer->antiguedad_laboral_meses);
        }

        $request = ['cliente_id' => (string) $customer->id, 'producto' => $product->code, 'origen' => 'banca_virtual'] + (isset($data['gestion_ref']) ? ['gestion_ref' => $data['gestion_ref']] : []);
        if ($product->type === 'debito') {
            $request['cuenta_id'] = $this->debitAccount($customer->id, $data['cuenta_id'] ?? null);
        } else {
            $missing = $this->profiles->missingCreditData($customer);
            if (! isset($data['monto_solicitado'])) {
                $missing[] = 'monto_solicitado';
            }
            if ($missing !== []) {
                throw ValidationException::withMessages(array_fill_keys($missing, 'Completa este dato para solicitar el producto.'));
            }
            $request['limite_solicitado'] = $data['monto_solicitado'];
            if (isset($data['monto_aprobado']) && Money::cents($data['monto_aprobado']) > Money::cents($data['monto_solicitado'])) {
                throw ValidationException::withMessages(['monto_aprobado' => 'El monto aprobado no puede superar el monto solicitado.']);
            }
        }
        if (isset($data['gestion_ref']) && CardApplication::where('gestion_ref', $data['gestion_ref'])->exists()) {
            throw new BusinessException('Ya existe una gestión con ese número de Banca Virtual.', 'MANAGEMENT_CONFLICT');
        }

        $created = $this->applications->create($request, $actor, preEvaluated: true);
        $application = CardApplication::findOrFail($created['id']);
        $profile = $this->profiles->profile($customer);
        $application->update(['solicitante' => array_intersect_key($profile, array_flip(['nombre_completo', 'correo_electronico', 'dpi_enmascarado', 'nit', 'telefono', 'fecha_nacimiento', 'tipo_empleo', 'ingresos_mensuales_q', 'antiguedad_laboral_meses']))]

            // Optional maximum approved by CP Analítica, informed by Banca: caps the limit the operator can assign.
            + (isset($data['monto_aprobado']) && $product->type !== 'debito' ? ['limite_aprobado_centavos' => Money::cents($data['monto_aprobado']), 'decision' => ['referencia' => $data['evaluacion_ref'] ?? 'banca-virtual', 'resultado' => 'aprobada', 'motivo' => 'Evaluación aprobada por CP Analítica e informada por Banca Virtual.', 'received_at' => now()->toIso8601String()]] : []));
        app(AlertService::class)->publish('gestion.recibida:'.$application->id, 'solicitudes.read', 'Gestión por aprobar', $customer->nombre.' aceptó '.$product->name.' en Banca Virtual. Revisa y apruébala.', 'solicitudes', (string) $application->id);

        return $application->refresh()->toArray();
    }

    private function debitAccount(string $customerId, ?string $accountId): string
    {
        $query = Account::where('cliente_id', (string) $customerId)->whereNull('system_key')->where('estado', 'activa');
        $account = $accountId ? $query->where('_id', $accountId)->first() : $query->orderBy('created_at')->first();
        if (! $account) {
            throw ValidationException::withMessages(['cuenta_id' => $accountId ? 'Selecciona una de tus cuentas activas.' : 'Abre una cuenta en "Cuentas" para solicitar la tarjeta de débito.']);
        }

        return (string) $account->id;
    }
}
