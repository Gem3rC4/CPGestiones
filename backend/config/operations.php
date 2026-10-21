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
    // Panel sessions: short-lived JWT access token plus a rotating refresh token (rubric: JWT + refresh).
    'jwt' => ['access_minutes' => (int) env('JWT_ACCESS_MINUTES', 15), 'refresh_hours' => (int) env('JWT_REFRESH_HOURS', 12), 'issuer' => 'cp-gestiones'],
    // Bank's own funds. Loans are disbursed from this account; it opens with this balance (cents).
    'master_account_opening_cents' => (int) env('MASTER_ACCOUNT_OPENING_CENTS', 5_000_000_000),
    // Reference exchange rate (external API). Banguat publishes the official rate; the second source is a fallback.
    'exchange' => ['banguat_url' => env('BANGUAT_URL', 'https://www.banguat.gob.gt/variables/ws/TipoCambio.asmx'), 'fallback_url' => env('EXCHANGE_FALLBACK_URL', 'https://open.er-api.com/v6/latest/USD'), 'cache_minutes' => 60],
    /*
     * Three roles (project requirement):
     * - administrador: full control; the only one who creates users and assigns roles.
     * - operador: Operations module only, to download customer transactions and generate reports.
     * - empresa: representative of ONE company (usuarios.empresa_id); loads, approves and downloads its payrolls.
     */
    'roles' => [
        'administrador' => ['*'],
        'operador' => ['movimientos.read', 'reportes.cuentas', 'reportes.transacciones', 'reportes.planillas'],
        'empresa' => ['empresas.read', 'colaboradores.read', 'planillas.read', 'planillas.write', 'planillas.approve', 'reportes.planillas'],
    ],
];
