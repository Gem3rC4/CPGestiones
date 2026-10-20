<?php

namespace App\Http\Controllers;

use App\Services\IntegrationSettings;
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
            'secreto' => ['sometimes', 'nullable', 'string', 'min:16', 'max:200'],
        ], ['url.url' => 'Ingresa una URL completa, por ejemplo https://banca.vercel.app/api/gestiones/webhook.', 'secreto.min' => 'Usa un secreto de al menos 16 caracteres.'])->validate();

        return $this->ok($settings->save($system, $data, (string) $request->attributes->get('actor')));
    }

    public function test(Request $request, string $system, IntegrationSettings $settings): JsonResponse
    {
        abort_unless(array_key_exists($system, IntegrationSettings::SYSTEMS), 404);

        return $this->ok($settings->test($system, (string) $request->attributes->get('actor')));
    }
}
