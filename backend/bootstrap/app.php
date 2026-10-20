<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\Permission;
use App\Http\Middleware\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\Exception;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['token' => AuthenticateToken::class, 'permit' => Permission::class]);
        $middleware->append(RequestContext::class);
        // Hosting platforms (Render) terminate TLS in a proxy; without this every client shares the proxy IP.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
        $middleware->trimStrings(except: ['contrasena']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Exception $e) {
            // Duplicate-key exceptions can contain personal data. Keep diagnostics without values.
            Log::warning('Fallo de MongoDB', ['code' => $e->getCode(), 'request_id' => request()->attributes->get('request_id')]);

            return false;
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        $exceptions->render(function (BusinessException $e, Request $r) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode, 'request_id' => $r->attributes->get('request_id')], $e->status);
        });
        $exceptions->render(function (ValidationException $e, Request $r) {
            return response()->json(['ok' => false, 'message' => 'Revisa los campos indicados.', 'code' => 'VALIDATION_ERROR', 'errors' => $e->errors(), 'request_id' => $r->attributes->get('request_id')], 422);
        });
        $exceptions->render(function (ModelNotFoundException $e) {
            return response()->json(['ok' => false, 'message' => 'No se encontró el registro.', 'code' => 'NOT_FOUND'], 404);
        });
        // Laravel converts ModelNotFoundException into this before rendering; never echo model class names.
        $exceptions->render(function (NotFoundHttpException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['ok' => false, 'message' => $e->getPrevious() instanceof ModelNotFoundException ? 'No se encontró el registro.' : 'La ruta solicitada no existe.', 'code' => 'NOT_FOUND', 'request_id' => $r->attributes->get('request_id')], 404);
            }
        });
        $exceptions->render(function (Exception $e) {
            $duplicate = $e->getCode() === 11000;

            return response()->json(['ok' => false, 'message' => $duplicate ? 'El registro ya existe o la operación está en curso. Reintenta con la misma clave.' : 'La base de datos no está disponible. Ningún movimiento parcial debe confirmarse.', 'code' => $duplicate ? 'CONFLICT' : 'DATABASE_UNAVAILABLE'], $duplicate ? 409 : 503);
        });
        $exceptions->respond(function ($response) {
            if ($response->getStatusCode() === 500 && request()->is('api/*')) {
                return response()->json(['ok' => false, 'message' => 'No fue posible completar la operación. Comunica el código de seguimiento.', 'code' => 'INTERNAL_ERROR', 'request_id' => request()->attributes->get('request_id')], 500);
            }

            return $response;
        });
    })->create();
