<?php

return [
    'timezone' => 'America/Guatemala',
    'token_minutes' => 60,
    'lock_minutes' => 15,
    'max_failures' => 5,
    'risk_url' => env('RISK_API_URL'),
    'risk_token' => env('RISK_API_TOKEN'),
    'bank_url' => env('BANK_WEBHOOK_URL'),
    'bank_secret' => env('BANK_WEBHOOK_SECRET'),
    // Only used by cp:bootstrap-admin on hosts without an interactive console (Render free).
    'initial_admin' => ['email' => env('INITIAL_ADMIN_EMAIL'), 'name' => env('INITIAL_ADMIN_NAME', 'Administrador'), 'password' => env('INITIAL_ADMIN_PASSWORD')],
    'roles' => [
        'administrador' => ['*'],
        'gerente' => ['dashboard.read', 'clientes.read', 'clientes.write', 'cuentas.read', 'cuentas.write', 'cuentas.manage', 'dinero.write', 'caja.write', 'productos.read', 'solicitudes.read', 'solicitudes.write', 'solicitudes.manage', 'tarjetas.read', 'tarjetas.write', 'empresas.read', 'empresas.write', 'planillas.read', 'planillas.write', 'planillas.approve', 'bitacora.read', 'integraciones.read'],
        'cajero' => ['dashboard.read', 'clientes.read', 'clientes.write', 'cuentas.read', 'cuentas.write', 'dinero.write', 'caja.write', 'productos.read', 'solicitudes.read', 'solicitudes.write', 'tarjetas.read'],
        'analista' => ['dashboard.read', 'clientes.read', 'cuentas.read', 'productos.read', 'solicitudes.read', 'bitacora.read'],
        'auditor' => ['dashboard.read', 'clientes.read', 'cuentas.read', 'productos.read', 'solicitudes.read', 'tarjetas.read', 'empresas.read', 'planillas.read', 'bitacora.read', 'seguridad.read', 'integraciones.read'],
        'empresa_operador' => ['empresas.read', 'planillas.read', 'planillas.write'],
        'empresa_aprobador' => ['empresas.read', 'planillas.read', 'planillas.approve'],
    ],
];
