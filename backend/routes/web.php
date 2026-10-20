<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'CP Gestiones', 'api' => '/api/v1', 'status' => 'API disponible']));
