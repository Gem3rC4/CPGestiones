<?php

return ['paths' => ['api/*'], 'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://localhost:5174,http://127.0.0.1:5174'))),
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-ID'],
    'exposed_headers' => ['X-Request-ID'], 'max_age' => 600, 'supports_credentials' => false];
