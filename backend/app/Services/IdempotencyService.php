<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function run(string $actor, string $key, string $action, array $data, callable $callback): array
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,120}$/D', $key)) {
            throw new BusinessException('Incluye Idempotency-Key de 8 a 120 caracteres.', 'IDEMPOTENCY_REQUIRED', 422);
        }
        if (isset($data['contrasena'])) {
            $data['contrasena'] = hash_hmac('sha256', $data['contrasena'], app('encrypter')->getKey());
        }
        $hash = hash('sha256', $action.'|'.json_encode($this->canonical($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::connection('mongodb')->transaction(function () use ($actor, $key, $hash, $callback) {
            $previous = IdempotencyRecord::where('actor', $actor)->where('key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $hash)) {
                    throw new BusinessException('Esta clave ya se utilizó con otros datos.', 'IDEMPOTENCY_MISMATCH');
                }

                return $previous->result;
            }
            $record = IdempotencyRecord::create(['actor' => $actor, 'key' => $key, 'fingerprint' => $hash]);
            $result = $callback();
            $record->update(['result' => $result]);

            return $result;
        }, attempts: 5);
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
