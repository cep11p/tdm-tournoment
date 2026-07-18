<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keycloak issuer (OIDC)
    |--------------------------------------------------------------------------
    |
    | Base issuer URL of the realm, e.g. http://localhost:8180/realms/tdm
    | Trailing slashes are normalized at runtime.
    |
    */

    'issuer' => env('KEYCLOAK_ISSUER'),

    /*
    |--------------------------------------------------------------------------
    | OIDC base URL (optional, Docker/internal)
    |--------------------------------------------------------------------------
    |
    | Used for OIDC discovery and JWKS HTTP requests. Falls back to issuer
    | when unset. Token iss validation always uses issuer above.
    |
    */

    'oidc_base_url' => env('KEYCLOAK_OIDC_BASE_URL', env('KEYCLOAK_ISSUER')),

    /*
    |--------------------------------------------------------------------------
    | API audience
    |--------------------------------------------------------------------------
    |
    | The access token "aud" claim must include this value (Keycloak client
    | configured as audience for the API resource).
    |
    */

    'api_audience' => env('KEYCLOAK_API_AUDIENCE'),

    /*
    |--------------------------------------------------------------------------
    | Frontend client ID (reference only in this slice)
    |--------------------------------------------------------------------------
    */

    'frontend_client_id' => env('KEYCLOAK_FRONTEND_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Allowed JWT algorithms
    |--------------------------------------------------------------------------
    */

    'allowed_algorithms' => ['RS256'],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */

    'discovery_cache_ttl' => (int) env('KEYCLOAK_DISCOVERY_CACHE_TTL', 3600),

    'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Clock skew (seconds)
    |--------------------------------------------------------------------------
    */

    'clock_skew' => (int) env('KEYCLOAK_CLOCK_SKEW', 60),

];
