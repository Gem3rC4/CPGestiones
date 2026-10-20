<?php

return ['default' => 'mongodb', 'connections' => ['mongodb' => [
    'driver' => 'mongodb', 'dsn' => env('DB_URI', 'mongodb://127.0.0.1:27017/?replicaSet=rs0'),
    'database' => env('DB_DATABASE', 'cp_gestiones'),
]]];
