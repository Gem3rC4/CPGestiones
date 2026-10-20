<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankManagementController;
use App\Http\Controllers\BankRegistrationController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\IntegrationTokenController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\QueryController;
use App\Http\Middleware\BankIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['ok' => true, 'name' => 'CP Gestiones', 'version' => '1.2.0']));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::middleware(['token', 'throttle:api'])->group(function () {
        Route::prefix('banca')->middleware(BankIntegration::class)->group(function () {
            Route::post('/auth/login', [CustomerAuthController::class, 'login'])->name('banca.login')->middleware('throttle:cliente-login');
            Route::get('/auth/sesion', [CustomerAuthController::class, 'session']);
            Route::post('/auth/logout', [CustomerAuthController::class, 'logout']);
            Route::post('/gestiones', [BankManagementController::class, 'store'])->name('banca.gestiones')->middleware('permit:solicitudes.write');
            Route::post('/eventos', [BankManagementController::class, 'event'])->name('banca.eventos')->middleware('permit:clientes.write');
            Route::post('/registro', [BankRegistrationController::class, 'store'])->name('banca.registro')->middleware(['permit:clientes.write', 'permit:cuentas.write']);
            Route::get('/perfil', [BankRegistrationController::class, 'profile'])->name('banca.perfil')->middleware('permit:clientes.read');
            Route::patch('/perfil/finanzas', [BankRegistrationController::class, 'updateFinancialProfile'])->name('banca.finanzas')->middleware('permit:clientes.write');
            Route::get('/resumen', [BankRegistrationController::class, 'summary'])->name('banca.resumen')->middleware(['permit:clientes.read', 'permit:cuentas.read']);
        });
        Route::get('/alertas', [AlertController::class, 'index']);
        Route::post('/alertas/leidas', [AlertController::class, 'read']);
        Route::post('/riesgos/evaluaciones', [BankManagementController::class, 'assessment'])->name('riesgos.evaluaciones')->middleware('permit:riesgos.decide');
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [QueryController::class, 'dashboard'])->middleware('permit:dashboard.read');
        Route::get('/usuarios-corporativos', fn () => response()->json(['ok' => true, 'data' => User::where('active', true)->whereIn('role', ['empresa_operador', 'empresa_aprobador'])->get(['id', 'name', 'role'])]))->middleware('permit:empresas.write');
        foreach (['usuarios', 'clientes', 'productos', 'cuentas', 'solicitudes', 'tarjetas', 'creditos', 'empresas', 'planillas', 'bitacora', 'seguridad'] as $resource) {
            Route::get('/'.$resource, [QueryController::class, 'index'])->defaults('resource', $resource)->middleware('permit:'.($resource === 'creditos' ? 'solicitudes' : $resource).'.read');
        }
        $post = function ($uri, $controller, $method, $name, $permit) {
            Route::post($uri, [$controller, $method])->name($name)->middleware('permit:'.$permit);
        };
        $post('/usuarios', AdminController::class, 'user', 'usuarios.store', 'usuarios.write');
        Route::patch('/usuarios/{id}', [AdminController::class, 'updateUser'])->name('usuarios.update')->middleware('permit:usuarios.write');
        $post('/clientes', AdminController::class, 'customer', 'clientes.store', 'clientes.write');
        Route::patch('/productos/{id}', [AdminController::class, 'product'])->name('productos.update')->middleware('permit:productos.write');
        $post('/cuentas', AccountController::class, 'store', 'cuentas.store', 'cuentas.write');
        Route::patch('/cuentas/{id}/estado', [AccountController::class, 'state'])->name('cuentas.state')->middleware('permit:cuentas.manage');
        Route::get('/cuentas/{id}/movimientos', [AccountController::class, 'movements'])->middleware('permit:cuentas.read');
        $post('/transferencias', AccountController::class, 'transfer', 'transferencias.store', 'dinero.write');
        $post('/caja', AccountController::class, 'cash', 'caja.store', 'caja.write');
        $post('/solicitudes', ApplicationController::class, 'store', 'solicitudes.store', 'solicitudes.write');
        Route::get('/solicitudes/{id}', [ApplicationController::class, 'show'])->middleware('permit:solicitudes.read');
        $post('/solicitudes/{id}/decision-riesgo', ApplicationController::class, 'risk', 'solicitudes.risk', 'riesgos.decide');
        $post('/solicitudes/{id}/transiciones', ApplicationController::class, 'transition', 'solicitudes.transition', 'solicitudes.read');
        $post('/tarjetas/{id}/consumos', CardController::class, 'charge', 'tarjetas.charge', 'tarjetas.write');
        $post('/tarjetas/{id}/pagos', CardController::class, 'pay', 'tarjetas.pay', 'tarjetas.write');
        Route::patch('/tarjetas/{id}/estado', [CardController::class, 'state'])->name('tarjetas.state')->middleware('permit:tarjetas.write');
        Route::get('/tarjetas/{id}/estados-cuenta', [CardController::class, 'statements'])->middleware('permit:tarjetas.read');
        $post('/empresas', PayrollController::class, 'company', 'empresas.store', 'empresas.write');
        $post('/planillas', PayrollController::class, 'store', 'planillas.store', 'planillas.write');
        $post('/planillas/{id}/autorizar', PayrollController::class, 'approve', 'planillas.approve', 'planillas.approve');
        Route::get('/riesgos/pendientes', [QueryController::class, 'riskPending'])->middleware('permit:riesgos.read');
        Route::get('/integraciones', [QueryController::class, 'integrations'])->middleware('permit:integraciones.read');
        Route::get('/integraciones/tokens', [IntegrationTokenController::class, 'index'])->middleware('permit:integraciones.write');
        Route::post('/integraciones/tokens', [IntegrationTokenController::class, 'store'])->middleware(['permit:integraciones.write', 'throttle:10,1']);
        Route::post('/integraciones/tokens/{id}/revocar', [IntegrationTokenController::class, 'revoke'])->middleware('permit:integraciones.write')->where('id', '[a-f0-9]{24}');
    });
});
