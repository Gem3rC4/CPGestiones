<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = 'required|string|regex:/^[a-f0-9]{24}$/D';
        $money = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'];
        $scope = ['cliente_ref' => 'sometimes|string|max:100', 'dpi' => 'sometimes|string|regex:/^[0-9]{13}$/D'];
        $roles = implode(',', array_keys(config('operations.roles')));
        $day = 'sometimes|nullable|integer|min:1|max:28';

        return match ($this->route()->getName()) {
            'auth.login' => ['email' => 'required|email|max:180', 'password' => 'required|string|max:200'],
            'auth.refresh' => ['refresh_token' => 'required|string|regex:/^[a-f0-9]{64}$/D'],
            'usuarios.store' => ['name' => 'required|string|max:150', 'email' => 'required|email|max:180', 'password' => 'required|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/', 'role' => 'required|in:'.$roles, 'empresa_id' => 'required_if:role,empresa|nullable|string|regex:/^[a-f0-9]{24}$/D'],
            'usuarios.update' => ['role' => 'required|in:'.$roles, 'active' => 'required|boolean', 'empresa_id' => 'required_if:role,empresa|nullable|string|regex:/^[a-f0-9]{24}$/D'],
            // Optional: Banca Virtual password and first account, so the customer can log in and receive payroll at once.
            'clientes.store' => ['nombre' => 'required|string|max:150', 'email' => 'required|email|max:180', 'dpi' => 'required|digits:13', 'nit' => 'required|string|max:20', 'telefono' => 'required|string|max:20', 'fecha_nacimiento' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today', 'ingreso_mensual' => $money,
                'contrasena' => array_merge(['sometimes'], array_values(array_filter(BankRegistrationRequest::passwordRules(), fn ($rule): bool => $rule !== 'required'))), 'producto_cuenta' => 'sometimes|nullable|in:ahorro,monetaria'],
            'productos.update' => ['name' => 'required|string|max:120', 'active' => 'required|boolean', 'annual_bps' => 'required|integer|min:0|max:6000', 'minimum_bps' => 'required|integer|min:0|max:10000', 'minimum_floor_cents' => 'required|integer|min:0|max:1000000', 'cutoff_day' => 'required|integer|min:1|max:31', 'payment_days' => 'required|integer|min:1|max:30'],
            'cuentas.store' => ['cliente_id' => $id, 'producto' => 'required|string|max:60', 'nombre' => 'sometimes|string|max:100'] + $scope,
            'cuentas.state' => ['estado' => 'required|in:activa,bloqueada', 'nota' => 'required|string|min:5|max:500'],
            'transferencias.store' => ['origen_id' => $id, 'destino_id' => 'required_without:destino_numero|prohibits:destino_numero|string|regex:/^[a-f0-9]{24}$/D', 'destino_numero' => 'required_without:destino_id|string|regex:/^[0-9]{10}$/D', 'monto' => $money, 'concepto' => 'required|string|min:3|max:160'] + $scope,
            'caja.store' => ['cuenta_id' => $id, 'monto' => $money, 'tipo' => 'required|in:deposito,retiro', 'concepto' => 'required|string|min:3|max:160'],
            'solicitudes.store' => ['cliente_id' => $id, 'producto' => 'required|string|max:60', 'limite_solicitado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D'] + $scope,
            'solicitudes.risk' => ['version' => 'required|integer|min:1', 'resultado' => 'required|in:aprobada,rechazada,revision_manual', 'limite_aprobado' => $money, 'referencia' => 'required|string|max:100', 'motivo' => 'required|string|min:5|max:1000'],
            'solicitudes.transition' => ['limite_asignado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'version' => 'required|integer|min:1', 'estado' => 'required|in:aprobada,rechazada,aceptada,cancelada,en_tramite,emitida', 'nota' => 'required|string|min:5|max:500', 'referencia_aceptacion' => 'sometimes|string|min:3|max:150',
                // Approval terms: loans (installments, rate, payment day, deposit account) and credit cards (cut-off, payment day, debit account).
                'plazo_meses' => 'sometimes|nullable|integer|min:1|max:360', 'tasa_anual' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]?)(\.[0-9]{1,2})?$/D'], 'dia_pago' => $day, 'dia_corte' => $day, 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D'] + $scope,
            'tarjetas.charge' => ['monto' => $money, 'concepto' => 'required|string|min:3|max:160'] + $scope,
            'tarjetas.pay' => ['monto' => $money, 'origen_id' => $id] + $scope,
            'tarjetas.state' => ['estado' => 'required|in:activa,bloqueada', 'nota' => 'required|string|min:5|max:500'] + $scope,
            // Corporate banking: the company gets its own account and, optionally, its representative user.
            'empresas.store' => ['nombre' => 'required|string|max:150', 'nit' => 'required|string|max:20', 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D', 'correo' => 'sometimes|nullable|email|max:180', 'telefono' => 'sometimes|nullable|string|max:20', 'direccion' => 'sometimes|nullable|string|max:200', 'producto_cuenta' => 'sometimes|nullable|in:ahorro,monetaria',
                'representante_nombre' => 'required_with:representante_correo|nullable|string|max:150', 'representante_correo' => 'sometimes|nullable|email|max:180', 'contrasena' => 'required_with:representante_correo|nullable|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'],
            'empresas.cuentas' => ['producto' => 'required|in:ahorro,monetaria', 'nombre' => 'sometimes|nullable|string|max:100'],
            'empresas.usuarios' => ['nombre' => 'required|string|max:150', 'correo' => 'required|email|max:180', 'contrasena' => 'required|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'],
            'planillas.store' => ['empresa_id' => $id, 'nombre' => 'required|string|max:120', 'pagos' => 'required|array|min:1|max:500', 'pagos.*.cuenta_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'pagos.*.cuenta_numero' => 'required_without_all:pagos.*.cuenta_id,pagos.*.colaborador_id|string|max:30', 'pagos.*.nombre' => 'required|string|max:150', 'pagos.*.monto' => $money, 'pagos.*.puesto' => 'sometimes|string|max:80', 'pagos.*.colaborador_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D'],
            'colaboradores.store' => ['empresa_id' => $id, 'cliente_id' => 'sometimes|string|regex:/^[a-f0-9]{24}$/D', 'puesto' => 'required|string|max:80', 'salario' => $money, 'cuenta_id' => 'required_without:cuenta_numero|string|regex:/^[a-f0-9]{24}$/D', 'cuenta_numero' => 'required_without:cuenta_id|string|regex:/^[0-9]{10}$/D'],
            'creditos.pay' => ['origen_id' => $id, 'monto' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D']] + $scope,
            'clientes.acceso' => ['activo' => 'required|boolean', 'nota' => 'required|string|min:5|max:500'],
            'clientes.sesiones' => [],
            // Customer passwords follow the Banca Virtual policy; staff passwords keep the stricter one.
            'clientes.contrasena' => ['contrasena' => BankRegistrationRequest::passwordRules(), 'nota' => 'required|string|min:5|max:500'],
            'usuarios.contrasena' => ['contrasena' => 'required|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'],
            'planillas.approve' => [],
            default => [],
        };
    }

    public function messages(): array
    {
        $passwords = match ($this->route()?->getName()) {
            'clientes.contrasena', 'clientes.store' => BankRegistrationRequest::passwordMessages('contrasena'),
            'usuarios.contrasena', 'empresas.store', 'empresas.usuarios' => ['contrasena.min' => 'Usa al menos 12 caracteres.', 'contrasena.regex' => 'Incluye al menos una mayúscula, una minúscula y un número.'],
            default => [],
        };

        return $passwords + ['required' => 'Este campo es obligatorio.', 'email' => 'Ingresa un correo válido.', 'min' => 'El valor es menor al mínimo permitido.', 'regex' => 'El formato no es válido.', 'digits' => 'El documento debe contener :digits dígitos.', 'in' => 'Selecciona una opción válida.', 'required_without' => 'Envía este campo o :values.', 'prohibits' => 'Envía solo uno de estos campos: :attribute o :other.', 'integer' => 'Este campo debe ser un número entero.', 'boolean' => 'Este campo debe ser verdadero o falso.', 'array' => 'Este campo debe ser una lista.', 'ip' => 'Ingresa una dirección IP válida.', 'string' => 'Este campo debe ser texto.', 'max' => 'El valor excede el máximo permitido (:max).', 'distinct' => 'Hay valores repetidos.', 'required_unless' => 'Este campo es obligatorio.', 'required_if' => 'Este campo es obligatorio.', 'required_without_all' => 'Este campo es obligatorio.', 'empresa_id.required_if' => 'Selecciona la empresa que representará este usuario.', 'refresh_token.regex' => 'El token de renovación no es válido.', 'dia_pago.max' => 'Usa un día entre 1 y 28.', 'dia_corte.max' => 'Usa un día entre 1 y 28.', 'tasa_anual.regex' => 'Escribe la tasa como porcentaje, por ejemplo 18.5.'];
    }
}
