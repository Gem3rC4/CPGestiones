<?php

use App\Exceptions\BusinessException;
use App\Services\BankRegistrationService;
use App\Services\IdempotencyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\Exception;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $uri, $key] = $argv;
if (! preg_match('/^cp_gestiones_registration_[0-9]+_test$/D', $database)) {
    exit(5);
}
config(['database.connections.mongodb.database' => $database, 'database.connections.mongodb.dsn' => $uri]);
DB::purge('mongodb');
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
try {
    app(IdempotencyService::class)->run('service:banca', $key, 'POST:api/v1/banca/registro', $data,
        fn (): array => app(BankRegistrationService::class)->register($data, 'service:banca'));
    echo 'OK';
} catch (BusinessException $exception) {
    echo $exception->errorCode;
} catch (Exception $exception) {
    echo $exception->getCode() === 11000 ? 'CONFLICT' : 'ERROR';
}
