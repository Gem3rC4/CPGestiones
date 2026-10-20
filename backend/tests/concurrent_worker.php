<?php

use App\Exceptions\BusinessException;
use App\Services\AccountService;
use App\Services\IdempotencyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$database,$uri,$source,$target,$key,$amount] = $argv;
if (! str_starts_with($database, 'cp_gestiones_') || ! str_ends_with($database, '_test')) {
    exit(5);
}
config(['database.connections.mongodb.database' => $database, 'database.connections.mongodb.dsn' => $uri]);
DB::purge('mongodb');
try {
    $result = app(IdempotencyService::class)->run('concurrency-test', $key, 'transfer', [], fn () => app(AccountService::class)->move($source, $target, (int) $amount, 'concurrency-test', 'Concurrent transfer', 'transferencia'));
    echo 'OK';
} catch (BusinessException $e) {
    echo $e->errorCode;
} catch (Throwable $e) {
    echo 'ERROR:'.$e->getCode();
    exit(1);
}
