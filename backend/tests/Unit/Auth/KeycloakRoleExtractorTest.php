<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\KeycloakRoleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class KeycloakRoleExtractorTest extends TestCase
{
    private KeycloakRoleExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new KeycloakRoleExtractor;
    }

    public function test_extracts_realm_roles(): void
    {
        $payload = new stdClass;
        $payload->realm_access = (object) [
            'roles' => ['organizer', 'offline_access', 'organizer'],
        ];

        $this->assertSame(['organizer', 'offline_access'], $this->extractor->extract($payload));
    }

    #[DataProvider('emptyRolePayloadProvider')]
    public function test_returns_empty_roles_when_missing(stdClass $payload): void
    {
        $this->assertSame([], $this->extractor->extract($payload));
    }

    public static function emptyRolePayloadProvider(): array
    {
        return [
            'without realm_access' => [new stdClass],
            'with empty roles' => [(function (): stdClass {
                $payload = new stdClass;
                $payload->realm_access = (object) ['roles' => []];

                return $payload;
            })()],
        ];
    }
}
