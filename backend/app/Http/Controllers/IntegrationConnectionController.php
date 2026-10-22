<?php

namespace App\Http\Controllers;

use App\Services\IntegrationSettings;
use App\Services\OutboxService;
use App\Services\SecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationConnectionController extends ApiController
{
    public function index(IntegrationSettings $settings): JsonResponse
    {
        return $this->ok($settings->overview());
    }

    // Not wrapped in mutation(): the idempotency record would keep the secret in its fingerprint input.
    public function update(Request $request, string $system, IntegrationSettings $settings): JsonResponse
    {
        $data = validator(['sistema' => $system] + $request->all(), [
            'sistema' => ['required', Rule::in(array_keys(IntegrationSettings::SYSTEMS))],
            'url' => ['required', 'string', 'url:http,https', 'max:300'],
            'secreto' => ['sometimes', 'nullable', 'string', 'min:16', 'max:200', 'regex:/^\S+$/'],
        ], ['url.url' => 'Ingresa una URL completa, por ejemplo https://banca.vercel.app/api/gestiones/webhook.', 'secreto.min' => 'Usa un secreto de al menos 16 caracteres.', 'secreto.regex' => 'El secreto no puede llevar espacios.'])->validate();

        return $this->ok($settings->save($system, $data, (string) $request->attributes->get('actor')));
    }

    /**
     * Puts failed events back in the queue (all of them, or one by id) after the cause was fixed.
     */
    public function retryEvents(Request $request, OutboxService $outbox): JsonResponse
    {
        $id = $request->validate(['id' => 'sometimes|nullable|string|regex:/^[a-f0-9]{24}$/D'])['id'] ?? null;
        $count = $outbox->retryFailed($id);
        app(SecurityService::class)->record('eventos_reintentados', (string) $request->attributes->get('actor'), ['eventos' => $count]);

        return $this->ok(['reintentados' => $count, 'enviados_ahora' => $count > 0 ? $outbox->deliver() : 0]);
    }

    public function test(Request $request, string $system, IntegrationSettings $settings): JsonResponse
    {
        abort_unless(array_key_exists($system, IntegrationSettings::SYSTEMS), 404);

        return $this->ok($settings->test($system, (string) $request->attributes->get('actor')));
    }
}
