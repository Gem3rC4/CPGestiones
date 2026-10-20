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
    // Billers shown in Banca Virtual > Pagar servicios. Payments settle into the internal 'servicios' account.
    'services' => [
        'empagua' => ['nombre' => 'Agua potable (EMPAGUA)', 'categoria' => 'Agua', 'campo' => 'Número de servicio'],
        'eegsa' => ['nombre' => 'Energía eléctrica (EEGSA)', 'categoria' => 'Energía', 'campo' => 'Número de contador (NIS)'],
        'energuate' => ['nombre' => 'Energía eléctrica (Energuate)', 'categoria' => 'Energía', 'campo' => 'Número de contador (NIS)'],
        'impuestos-municipales' => ['nombre' => 'Impuestos municipales', 'categoria' => 'Gobierno', 'campo' => 'Número de boleta'],
        'internet-cable' => ['nombre' => 'Internet y cable', 'categoria' => 'Internet', 'campo' => 'Número de contrato'],
        'claro' => ['nombre' => 'Telefonía móvil (Claro)', 'categoria' => 'Telefonía', 'campo' => 'Número de teléfono'],
        'tigo' => ['nombre' => 'Telefonía móvil (Tigo)', 'categoria' => 'Telefonía', 'campo' => 'Número de teléfono'],
    ],
    'roles' => [
        'administrador' => ['*'],
        'gerente' => ['dashboard.read', 'clientes.read', 'clientes.write', 'clientes.manage', 'cuentas.read', 'cuentas.write', 'cuentas.manage', 'dinero.write', 'caja.write', 'productos.read', 'solicitudes.read', 'solicitudes.write', 'solicitudes.manage', 'tarjetas.read', 'tarjetas.write', 'empresas.read', 'empresas.write', 'planillas.read', 'planillas.write', 'planillas.approve', 'bitacora.read', 'integraciones.read'],
        'cajero' => ['dashboard.read', 'clientes.read', 'clientes.write', 'cuentas.read', 'cuentas.write', 'dinero.write', 'caja.write', 'productos.read', 'solicitudes.read', 'solicitudes.write', 'tarjetas.read'],
        'analista' => ['dashboard.read', 'clientes.read', 'cuentas.read', 'productos.read', 'solicitudes.read', 'bitacora.read'],
        'auditor' => ['dashboard.read', 'clientes.read', 'cuentas.read', 'productos.read', 'solicitudes.read', 'tarjetas.read', 'empresas.read', 'planillas.read', 'bitacora.read', 'seguridad.read', 'integraciones.read'],
        'empresa_operador' => ['empresas.read', 'planillas.read', 'planillas.write'],
        'empresa_aprobador' => ['empresas.read', 'planillas.read', 'planillas.approve'],
    ],
];
