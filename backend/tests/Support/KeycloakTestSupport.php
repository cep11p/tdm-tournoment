<?php

namespace Tests\Support;

use App\Support\Auth\JwksRepository;
use App\Support\Auth\OidcConfigurationRepository;
use Illuminate\Support\Facades\Cache;

final class KeycloakTestSupport
{
    public static function setUp(): void
    {
        KeycloakTestKeys::boot();
        KeycloakTestKeys::applyConfig();
        KeycloakTestKeys::configureHttpFakes();
        Cache::flush();
    }

    public static function primeOidcCache(): void
    {
        app(OidcConfigurationRepository::class)->getConfiguration();
    }

    public static function primeJwksCache(): void
    {
        self::primeOidcCache();
        app(JwksRepository::class)->resolvePublicKey(KeycloakTestKeys::kid());
    }
}
