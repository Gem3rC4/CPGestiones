<?php

namespace App\Http\Controllers;

use App\Models\AccessToken;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationTokenController extends ApiController
{
    public function index(): JsonResponse
    {
        $tokens = AccessToken::where('kind', 'service')->where('expires_at', '>', now())->orderBy('created_at', 'desc')->get();
        $names = User::whereIn('_id', $tokens->pluck('created_by')->filter()->unique()->values()->all())->get()->mapWithKeys(fn (User $u): array => [(string) $u->id => $u->name]);

        return $this->ok($tokens->map(fn (AccessToken $t): array => [
            'id' => (string) $t->id, 'client' => $t->client, 'hint' => $t->hint,
            'created_at' => $t->created_at?->toIso8601String(), 'expires_at' => $t->expires_at?->toIso8601String(),
            'created_by' => $names[$t->created_by] ?? ($t->created_by ? 'Consola' : null),
        ])->values());
    }

    // Not wrapped in mutation(): an idempotency record would persist the plain token in the database.
    public function store(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate(['cliente' => 'required|in:banca,riesgos', 'dias' => 'required|integer|in:30,90,180,365']);
        $actor = (string) $request->attributes->get('actor');
        $issued = $auth->createServiceToken($data['cliente'], (int) $data['dias'], $actor);
        app(SecurityService::class)->record('token_integracion_emitido', $actor, ['token_id' => $issued['id'], 'client' => $issued['client'], 'dias' => (int) $data['dias']]);

        return $this->ok($issued, 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $token = AccessToken::where('_id', $id)->where('kind', 'service')->firstOrFail();
        $token->delete();
        app(SecurityService::class)->record('token_integracion_revocado', (string) $request->attributes->get('actor'), ['token_id' => $id, 'client' => $token->client]);

        return $this->ok(['revocado' => true]);
    }
}
