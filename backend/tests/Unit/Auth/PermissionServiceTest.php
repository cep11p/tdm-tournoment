<?php

namespace Tests\Unit\Auth;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Auth\AuthenticatedIdentity;
use App\Support\Auth\PermissionService;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PermissionService::class);
    }

    public function test_admin_receives_all_permissions(): void
    {
        $permissions = $this->service->resolvePermissions(['admin']);

        $this->assertSame(Permission::all(), $permissions);
    }

    public function test_organizer_receives_management_permissions_without_administration(): void
    {
        $permissions = $this->service->resolvePermissions(['organizer']);

        $this->assertContains(Permission::TournamentsManage, $permissions);
        $this->assertContains(Permission::CompetitionsManage, $permissions);
        $this->assertContains(Permission::MatchesRecordResult, $permissions);
        $this->assertNotContains(Permission::AuditView, $permissions);
        $this->assertNotContains(Permission::UsersManage, $permissions);
        $this->assertNotContains(Permission::CatalogManage, $permissions);
        $this->assertNotContains(Permission::MatchesCorrectResult, $permissions);
    }

    public function test_scorekeeper_can_record_results_but_not_manage_tournaments(): void
    {
        $permissions = $this->service->resolvePermissions(['scorekeeper']);

        $this->assertContains(Permission::MatchesRecordResult, $permissions);
        $this->assertContains(Permission::TournamentsView, $permissions);
        $this->assertNotContains(Permission::TournamentsManage, $permissions);
        $this->assertNotContains(Permission::CompetitionsManage, $permissions);
    }

    public function test_player_only_receives_view_permissions(): void
    {
        $permissions = $this->service->resolvePermissions(['player']);

        foreach ($permissions as $permission) {
            $this->assertTrue(
                str_ends_with($permission->value, '.view'),
                'Se esperaba un permiso de lectura, se obtuvo: '.$permission->value,
            );
        }

        $this->assertNotContains(Permission::MatchesRecordResult, $permissions);
    }

    public function test_unknown_roles_are_ignored(): void
    {
        $permissions = $this->service->resolvePermissions(['offline_access', 'uma_authorization']);

        $this->assertSame([], $permissions);
    }

    public function test_context_for_builds_authenticated_context(): void
    {
        $identity = new AuthenticatedIdentity(
            subject: 'ctx-subject',
            email: 'ctx@example.com',
            name: 'Context User',
            preferredUsername: 'ctx',
            roles: ['scorekeeper'],
            claims: [],
        );

        $user = User::factory()->create([
            'keycloak_id' => 'ctx-subject',
        ]);

        $context = $this->service->contextFor($user, $identity);

        $this->assertSame($user->id, $context->user->id);
        $this->assertSame($identity, $context->identity);
        $this->assertTrue($context->has(Permission::MatchesRecordResult));
        $this->assertFalse($context->has(Permission::TournamentsManage));
    }
}
