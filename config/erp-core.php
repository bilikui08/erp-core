<?php

return [

    'auth' => [
        // Clave pública de auth-api (Passport). Solo verifica tokens, nunca firma.
        'public_key' => env('AUTH_JWT_PUBLIC_KEY_PATH', storage_path('oauth-public.key')),

        // Tokens anteriores a las empresas (sin claim `org`): se aceptan como la empresa propia de la persona, con todos los
        // permisos. Es una compatibilidad transitoria de los servicios que ya existían; un servicio nuevo no la necesita.
        'accept_legacy_tokens' => (bool) env('AUTH_ACCEPT_LEGACY_TOKENS', false),
    ],

];
