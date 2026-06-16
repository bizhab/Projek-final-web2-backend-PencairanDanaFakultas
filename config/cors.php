<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    | Konfigurasi CORS untuk membolehkan frontend Vue.js (dev server Vite)
    | mengakses API backend Laravel.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',   // Vite dev server default
        'http://localhost:3000',   // fallback
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Penting: true agar cookie session/token dikirim bersama request
    'supports_credentials' => true,
];
