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
        $roles = implode(',', config('operations.staff_roles'));
        $day = 'sometimes|nullable|integer|min:1|max:28';
        $name = "regex:/^[\p{L}\p{M}][\p{L}\p{M} '\x{2019}.-]*$/uD";

        return match ($this->route()->getName()) {
            'auth.login' => ['email' => 'required|email|max:180', 'password' => 'required|string|max:200'],
            'auth.refresh' => ['refresh_token' => 'required|string|regex:/^[a-f0-9]{64}$/D'],
            'usuarios.store' => ['name' => 'required|string|max:150', 'email' => 'required|email|max:180', 'password' => 'required|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/', 'role' => 'required|in:'.$roles, 'empresa_id' => 'required_if:role,empresa|nullable|string|regex:/^[a-f0-9]{24}$/D'],
            'usuarios.update' => ['role' => 'required|in:'.$roles, 'active' => 'required|boolean', 'empresa_id' => 'required_if:role,empresa|nullable|string|regex:/^[a-f0-9]{24}$/D'],
            // The customer file every other system relies on: DPI is the unique key that links the person to Banca Virtual.
            'clientes.store' => ['nombres' => ['required', 'string', 'max:75', $name], 'apellidos' => ['required', 'string', 'max:74', $name], 'email' => 'required|email|max:180', 'dpi' => 'required|string|regex:/^[0-9]{13}$/D',
                'nit' => ['required', 'string', 'max:20', 'regex:/^[0-9]{1,12}-?[0-9Kk]$/D'], 'telefono' => ['required', 'string', 'regex:/^[0-9+][0-9 -]{7,19}$/D'],
                'fecha_nacimiento' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:'.now('America/Guatemala')->subYears(18)->toDateString(), 'ingreso_mensual' => $money,
                'antiguedad_laboral_meses' => 'required|integer|min:0|max:1500'],
            // Editing the customer file: only the fields sent change, with the same rules as when it was registered.
            'clientes.update' => ['nombres' => ['sometimes', 'string', 'max:75', $name], 'apellidos' => ['sometimes', 'string', 'max:74', $name], 'email' => 'sometimes|email|max:180', 'dpi' => 'sometimes|string|regex:/^[0-9]{13}$/D',
                'nit' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9]{1,12}-?[0-9Kk]$/D'], 'telefono' => ['sometimes', 'string', 'regex:/^[0-9+][0-9 -]{7,19}$/D'],
                'fecha_nacimiento' => 'sometimes|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:'.now('America/Guatemala')->subYears(18)->toDateString(),
                'ingreso_mensual' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'antiguedad_laboral_meses' => 'sometimes|integer|min:0|max:1500'],
            // The opening amount (minimum Q100.00) becomes the first balance of the account.
            'cuentas.store' => ['cliente_id' => $id, 'producto' => 'required|in:ahorro,monetaria', 'monto_apertura' => $money] + $scope,
            'cuentas.state' => ['estado' => 'required|in:activa,bloqueada,cerrada', 'nota' => 'required|string|min:5|max:500'],
            'transferencias.store' => ['origen_id' => $id, 'destino_id' => 'required_without:destino_numero|prohibits:destino_numero|string|regex:/^[a-f0-9]{24}$/D', 'destino_numero' => 'required_without:destino_id|string|regex:/^[0-9]{10}$/D', 'monto' => $money, 'concepto' => 'required|string|min:3|max:160'] + $scope,
            'caja.store' => ['cuenta_id' => $id, 'monto' => $money, 'tipo' => 'required|in:deposito,retiro', 'concepto' => 'required|string|min:3|max:160'],
            'solicitudes.store' => ['cliente_id' => $id, 'producto' => 'required|string|max:60', 'limite_solicitado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D'] + $scope,
            'solicitudes.risk' => ['version' => 'required|integer|min:1', 'resultado' => 'required|in:aprobada,rechazada,revision_manual', 'limite_aprobado' => $money, 'referencia' => 'required|string|max:100', 'motivo' => 'required|string|min:5|max:1000'],
            'solicitudes.transition' => ['limite_asignado' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'version' => 'required|integer|min:1', 'estado' => 'required|in:aprobada,rechazada,aceptada,cancelada,en_tramite,emitida', 'nota' => 'required|string|min:5|max:500', 'referencia_aceptacion' => 'sometimes|string|min:3|max:150',
                // Approval terms: loans (installments, rate, payment day, deposit account) and credit cards (cut-off, payment day, debit account).
                'plazo_meses' => 'sometimes|nullable|integer|min:1|max:360', 'tasa_anual' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]?)(\.[0-9]{1,2})?$/D'], 'dia_pago' => $day, 'dia_corte' => $day, 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D'] + $scope,
            'tarjetas.charge' => ['monto' => $money, 'concepto' => 'required|string|min:3|max:160'] + $scope,
            'tarjetas.pay' => ['monto' => $money, 'origen_id' => 'required_without:origen_numero|prohibits:origen_numero|string|regex:/^[a-f0-9]{24}$/D', 'origen_numero' => 'required_without:origen_id|string|regex:/^[0-9]{10}$/D'] + $scope,
            'tarjetas.state' => ['estado' => 'required|in:activa,bloqueada', 'nota' => 'required|string|min:5|max:500'] + $scope,
            // Corporate banking: the company gets its own account and, optionally, its representative user.
            'empresas.store' => ['nombre' => 'required|string|min:2|max:150', 'razon_social' => 'sometimes|required|string|min:3|max:200', 'representante_legal' => 'sometimes|nullable|string|max:150', 'nit' => ['required', 'string', 'max:20', 'regex:/^[0-9]{1,12}-?[0-9Kk]$/D'], 'cuenta_id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D', 'correo' => 'sometimes|nullable|email|max:180', 'telefono' => 'sometimes|nullable|string|max:20', 'direccion' => 'sometimes|nullable|string|max:200', 'producto_cuenta' => 'sometimes|nullable|in:ahorro,monetaria', 'monto_apertura' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
                'representante_nombre' => 'required_with:representante_correo|nullable|string|max:150', 'representante_correo' => 'sometimes|nullable|email|max:180', 'contrasena' => 'required_with:representante_correo|nullable|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'],
            'empresas.usuarios.contrasena' => ['contrasena' => 'required|string|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'],
            'empresas.usuarios.estado' => ['activo' => 'required|boolean'],
            'empresas.fondeos' => ['cuenta_id' => $id, 'tipo' => 'required|in:deposito,retiro', 'monto' => $money, 'concepto' => 'required|string|min:3|max:160'],
            'empresas.cuentas' => ['producto' => 'required|in:ahorro,monetaria', 'nombre' => 'sometimes|nullable|string|max:100', 'monto_apertura' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D']],
            // Purchase authorization: the merchant is required for the central log.
            'tarjetas.autorizar' => ['monto' => $money, 'comercio' => 'required|string|min:2|max:120', 'concepto' => 'sometimes|nullable|string|max:120', 'referencia' => 'sometimes|nullable|string|max:80'] + $scope,
            'clientes.eliminar' => ['nota' => 'sometimes|nullable|string|max:500'],
            'empresas.eliminar' => ['nota' => 'sometimes|nullable|string|max:500'],
            'concentradora.ingreso' => ['monto' => $money, 'concepto' => 'required|string|min:3|max:160'],
            'mantenimiento.limpieza' => ['confirmacion' => 'required|string|in:ELIMINAR TODO'],
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
            'clientes.contrasena' => BankRegistrationRequest::passwordMessages('contrasena'),
            'clientes.store', 'clientes.update' => ['fecha_nacimiento.before_or_equal' => 'El cliente debe ser mayor de edad (18 años o más).', 'fecha_nacimiento.date_format' => 'Usa una fecha válida (AAAA-MM-DD).', 'nombres.regex' => 'Usa solo letras.', 'apellidos.regex' => 'Usa solo letras.', 'telefono.regex' => 'Escribe un teléfono válido, por ejemplo 5555-1234.', 'dpi.regex' => 'El DPI debe tener exactamente 13 dígitos.', 'antiguedad_laboral_meses.max' => 'Usa un número de meses válido.'],
            'cuentas.store' => ['monto_apertura.regex' => 'Escribe el monto con hasta dos decimales, por ejemplo 100.00.', 'producto.in' => 'Selecciona cuenta de ahorro o monetaria.'],
            'usuarios.contrasena', 'empresas.store', 'empresas.usuarios', 'empresas.usuarios.contrasena' => ['contrasena.min' => 'Usa al menos 12 caracteres.', 'contrasena.regex' => 'Incluye al menos una mayúscula, una minúscula y un número.'],
            default => [],
        };

        return $passwords + ['required' => 'Este campo es obligatorio.', 'email' => 'Ingresa un correo válido.', 'min' => 'El valor es menor al mínimo permitido.', 'regex' => 'El formato no es válido.', 'digits' => 'El documento debe contener :digits dígitos.', 'in' => 'Selecciona una opción válida.', 'required_without' => 'Envía este campo o :values.', 'prohibits' => 'Envía solo uno de estos campos: :attribute o :other.', 'integer' => 'Este campo debe ser un número entero.', 'boolean' => 'Este campo debe ser verdadero o falso.', 'array' => 'Este campo debe ser una lista.', 'ip' => 'Ingresa una dirección IP válida.', 'string' => 'Este campo debe ser texto.', 'max' => 'El valor excede el máximo permitido (:max).', 'distinct' => 'Hay valores repetidos.', 'required_unless' => 'Este campo es obligatorio.', 'required_if' => 'Este campo es obligatorio.', 'required_without_all' => 'Este campo es obligatorio.', 'empresa_id.required_if' => 'Selecciona la empresa que representará este usuario.', 'refresh_token.regex' => 'El token de renovación no es válido.', 'dia_pago.max' => 'Usa un día entre 1 y 28.', 'dia_corte.max' => 'Usa un día entre 1 y 28.', 'tasa_anual.regex' => 'Escribe la tasa como porcentaje, por ejemplo 18.5.', 'nit.regex' => 'Escribe el NIT sin espacios, por ejemplo 1234567-8 o 1234567K.'];
    }
}
