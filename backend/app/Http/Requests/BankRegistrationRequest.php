<?php

namespace App\Http\Requests;

use App\Exceptions\BusinessException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

class BankRegistrationRequest extends BankCustomerRequest
{
    protected function prepareForValidation(): void
    {
        $changes = [];
        foreach (['correo_electronico', 'tipo_empleo'] as $key) {
            if (is_string($this->input($key))) {
                $changes[$key] = mb_strtolower(trim($this->input($key)));
            }
        }
        $income = $this->input('ingresos_mensuales_q');
        if (is_int($income) || is_float($income)) {
            $income = json_encode($income, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (is_string($income)) {
            try {
                $changes['ingresos_mensuales_q'] = Money::format(Money::cents($income, true));
            } catch (BusinessException) {
                $changes['ingresos_mensuales_q'] = $income;
            }
        }
        if (is_string($this->input('numero_cuenta'))) {
            $changes['numero_cuenta'] = preg_replace('/[\s-]+/', '', $this->input('numero_cuenta'));
        }
        $date = $this->input('fecha_nacimiento');
        if (is_string($date) && preg_match('/^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/D', $date, $parts)) {
            $changes['fecha_nacimiento'] = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }
        $this->merge($changes);
    }

    protected function financialRules(): array
    {
        return [
            'tipo_empleo' => ['required', 'string', 'in:asalariado,independiente'],
            'ingresos_mensuales_q' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Money::cents($value, true);
                } catch (BusinessException) {
                    $fail('Usa un importe entre 0.00 y 1000000000.00, con un máximo de dos decimales.');
                }
            }],
            'antiguedad_laboral_meses' => ['required', 'integer', 'min:0', 'max:1500'],
        ];
    }

    /**
     * Customer password policy, shared by registration, change and recovery.
     * Matches what Banca Virtual shows: at least 8 characters with letters and numbers.
     */
    public static function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'min:8', 'max:72', 'regex:/\p{L}/u', 'regex:/[0-9]/', function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen($value) > 72 || str_contains($value, chr(0))) {
                $fail('Usa una contraseña de hasta 72 bytes, sin caracteres nulos.');
            }
        }];
    }

    public static function passwordMessages(string $field): array
    {
        return [$field.'.min' => 'Usa al menos 8 caracteres.', $field.'.regex' => 'Incluye al menos una letra y un número.'];
    }

    protected function birthDateRules(): array
    {
        return ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.CarbonImmutable::now('America/Guatemala')->subYears(18)->toDateString()];
    }

    protected function nitRules(): array
    {
        return ['string', 'max:20', 'regex:/^[0-9]{1,12}-?[0-9Kk]$/D'];
    }

    /**
     * Same financial rules, but only validated when the field is sent.
     */
    protected function optionalFinancialRules(): array
    {
        return array_map(fn (array $rules): array => array_map(fn ($rule) => $rule === 'required' ? 'sometimes' : $rule, $rules), $this->financialRules());
    }

    // Sign-up form of the app: names, e-mail, password, DPI and birth date (account number optional).
    // NIT and the financial fields stay optional so the original, longer form keeps working.
    public function rules(): array
    {
        return [
            'dpi' => ['required', 'string', 'regex:/^[0-9]{13}$/D'],
            // Optional: when sent, the account must belong to that DPI. The sign-up form does not ask for it.
            'numero_cuenta' => ['sometimes', 'string', 'regex:/^[0-9]{10}$/D'],
            'cliente_ref' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D'],
            'contrasena' => self::passwordRules(),
            'nombre' => ['required', 'string', 'max:75', "regex:/^[\p{L}\p{M}][\p{L}\p{M} '\x{2019}-]*$/uD"],
            'apellido' => ['required', 'string', 'max:74', "regex:/^[\p{L}\p{M}][\p{L}\p{M} '\x{2019}-]*$/uD"],
            'correo_electronico' => ['required', 'string', 'email:rfc', 'max:180'],
            'fecha_nacimiento' => $this->birthDateRules(),
            'nit' => ['sometimes', ...$this->nitRules()],
        ] + $this->optionalFinancialRules();
    }

    public function messages(): array
    {
        return parent::messages() + self::passwordMessages('contrasena') + ['numero_cuenta.regex' => 'El número de cuenta tiene 10 dígitos.'];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (! $validator->errors()->isEmpty() || ! $this->has('antiguedad_laboral_meses') || ! $this->has('fecha_nacimiento')) {
                return;
            }
            $birth = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('fecha_nacimiento'), 'America/Guatemala');
            if ((int) $this->input('antiguedad_laboral_meses') > (int) $birth->diffInMonths(CarbonImmutable::now('America/Guatemala'))) {
                $validator->errors()->add('antiguedad_laboral_meses', 'La antigüedad laboral no puede superar la edad del cliente en meses.');
            }
        }];
    }
}
