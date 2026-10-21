<?php

use App\Models\AccessToken;
use App\Models\OutboxEvent;
use App\Models\Payroll;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CardService;
use App\Services\CollectionService;
use App\Services\InstallService;
use App\Services\OutboxService;
use App\Services\PayrollService;
use App\Services\RegistrationUpgradeService;
use App\Services\SavingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cp:install', function (InstallService $service) {
    $service->indexes();
    $service->catalog();
    $service->fundMasterAccount();
    $purged = $service->purgeCorporateDemo();
    $roles = $service->migrateRoles();
    $this->info('Colecciones, índices, catálogo y cuenta concentradora listos.');
    if (array_sum($purged) > 0) {
        $this->info('Datos de prueba corporativos eliminados: '.json_encode($purged, JSON_UNESCAPED_UNICODE));
    }
    if ($roles > 0) {
        $this->info('Usuarios migrados a los nuevos roles: '.$roles);
    }
});
Artisan::command('cp:upgrade-registration', function (RegistrationUpgradeService $service) {
    $this->info('Clientes actualizados: '.$service->run().'. Índices de registro preparados.');
})->purpose('Cifrar DPI anteriores y comprobar duplicados; ejecutar en mantenimiento.');
Artisan::command('cp:cobros', function (CollectionService $service) {
    $this->line(json_encode($service->run(), JSON_UNESCAPED_UNICODE));
})->purpose('Débito automático de cuotas de préstamo y de estados de cuenta de tarjeta vencidos.');
Artisan::command('cp:admin {email} {name}', function () {
    $password = $this->secret('Contraseña (12 caracteres, mayúscula, minúscula y número)');
    validator(['password' => $password, 'email' => $this->argument('email')], ['email' => 'required|email', 'password' => 'required|min:12|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/'])->validate();
    User::create(['email' => strtolower($this->argument('email')), 'name' => $this->argument('name'), 'role' => 'administrador', 'active' => true, 'failed_attempts' => 0, 'password' => Hash::make($password)]);
    $this->info('Administrador creado.');
});
Artisan::command('cp:bootstrap-admin', function () {
    if (User::where('role', 'administrador')->where('active', true)->exists()) {
        $this->line('Ya existe un administrador activo; no se creó otro.');

        return 0;
    }
    $admin = config('operations.initial_admin');
    if (! $admin['email'] || ! $admin['password']) {
        $this->warn('No hay administradores. Define INITIAL_ADMIN_EMAIL e INITIAL_ADMIN_PASSWORD para crear el primero.');

        return 0;
    }
    $validator = validator($admin, ['email' => 'required|email', 'password' => 'required|min:12|max:72|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/']);
    if ($validator->fails()) {
        $this->error('INITIAL_ADMIN_PASSWORD requiere 12 a 72 caracteres con mayúscula, minúscula y número.');

        return 1;
    }
    User::create(['email' => mb_strtolower(trim($admin['email'])), 'name' => $admin['name'], 'role' => 'administrador', 'active' => true, 'failed_attempts' => 0, 'password' => Hash::make($admin['password'])]);
    $this->info('Administrador inicial creado. Ya puedes quitar INITIAL_ADMIN_PASSWORD del servidor.');
})->purpose('Crear el primer administrador desde variables de entorno, solo si no existe ninguno.');
Artisan::command('cp:token {client}', function (AuthService $service) {
    $this->info('Guarda este secreto solo en el servidor del sistema correspondiente. Caduca en 30 días.');
    $this->line($service->issueService($this->argument('client')));
});
Artisan::command('cp:revoke {client}', function () {
    AccessToken::where('kind', 'service')->where('client', $this->argument('client'))->delete();
    $this->info('Tokens de integración revocados.');
});
Artisan::command('cp:close-day {--date=}', function (CardService $cards, SavingsService $savings) {
    $date = $this->option('date') ?: now()->timezone(config('operations.timezone'))->toDateString();
    $results = ['tarjetas' => $cards->closeDay($date), 'ahorro' => $savings->closeDay($date)];
    $this->line(json_encode($results, JSON_UNESCAPED_UNICODE));
    if ($results['tarjetas']['pendientes'] !== [] || $results['ahorro']['pendientes'] !== []) {
        $this->warn('Hay registros pendientes de conciliación. Los demás se cerraron correctamente.');
    }
});
Artisan::command('cp:payrolls', function (PayrollService $service) {
    foreach (Payroll::where('estado', 'en_cola')->limit(10)->get() as $p) {
        try {
            $service->process((string) $p->id);
            $this->info('Planilla pagada: '.$p->nombre);
        } catch (Throwable $e) {
            $p->refresh();
            if ($p->estado === 'en_cola') {
                $p->update(['ultimo_error' => 'No se pudo dispersar. Revisa fondos y cuentas. Se reintentará sin duplicar pagos.']);
            }$this->warn('Pendiente: '.$p->nombre);
            report($e);
        }
    }
});
Artisan::command('cp:outbox', function (OutboxService $service) {
    $this->info('Eventos enviados: '.$service->deliver());
});
Artisan::command('cp:outbox-retry {id}', function () {
    $e = OutboxEvent::findOrFail($this->argument('id'));
    if ($e->status === 'fallido') {
        $e->update(['status' => 'pendiente', 'attempts' => 0, 'next_attempt' => null]);
    }$this->info('Evento preparado.');
});
Schedule::command('cp:payrolls')->everyMinute()->withoutOverlapping();
Schedule::command('cp:outbox')->everyMinute()->withoutOverlapping();
Schedule::command('cp:close-day')->dailyAt('23:59')->timezone('America/Guatemala')->withoutOverlapping();
Schedule::command('cp:cobros')->dailyAt('18:00')->timezone('America/Guatemala')->withoutOverlapping();
