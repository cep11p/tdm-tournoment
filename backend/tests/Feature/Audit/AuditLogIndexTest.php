<?php

namespace Tests\Feature\Audit;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_returns_activities_in_descending_order(): void
    {
        $older = $this->createAuditActivity(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            createdAt: Carbon::parse('2026-07-10 10:00:00'),
        );

        $newer = $this->createAuditActivity(
            action: AuditAction::BRACKET_CREATED,
            logName: 'bracket',
            createdAt: Carbon::parse('2026-07-15 10:00:00'),
        );

        $response = $this->getJson('/api/v1/audit-logs', $this->adminHeaders())
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], array_slice($ids, 0, 2));
    }

    public function test_paginates_results(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->createAuditActivity(
                action: AuditAction::GROUPS_REGENERATED,
                logName: 'groups',
                createdAt: Carbon::parse('2026-07-10 10:0'.$index.':00'),
            );
        }

        $this->getJson('/api/v1/audit-logs?per_page=2&page=2', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_by_action(): void
    {
        $match = $this->createAuditActivity(AuditAction::GAME_SET_RECORDED, 'games');
        $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');

        $response = $this->getJson(
            '/api/v1/audit-logs?action='.AuditAction::GAME_SET_RECORDED->value,
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_filters_by_log_name(): void
    {
        $match = $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');
        $this->createAuditActivity(AuditAction::GROUPS_REGENERATED, 'groups');

        $response = $this->getJson('/api/v1/audit-logs?log_name=bracket', $this->adminHeaders())
            ->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_filters_by_actor(): void
    {
        $user = User::factory()->create([
            'keycloak_id' => 'audit-filter-actor',
            'name' => 'Actor Filtro',
            'email' => 'actor@example.com',
        ]);

        Auth::setUser($user);

        $match = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $this->createCompetition(),
        ));

        Auth::logout();

        $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');

        $response = $this->getJson(
            "/api/v1/audit-logs?actor_id={$user->id}",
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_filters_by_tournament_competition_group_and_game(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Filtro',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp Filtro',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);

        $group = Group::query()->create([
            'competition_id' => $competition->id,
            'name' => 'Grupo A',
        ]);

        $playerOne = Player::query()->create([
            'first_name' => 'Jugador',
            'last_name' => 'Uno',
        ]);

        $playerTwo = Player::query()->create([
            'first_name' => 'Jugador',
            'last_name' => 'Dos',
        ]);

        $game = Game::query()->create([
            'competition_id' => $competition->id,
            'group_id' => $group->id,
            'player1_id' => $playerOne->id,
            'player2_id' => $playerTwo->id,
            'status' => 'pending',
        ]);

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GAME_SET_RECORDED,
            logName: 'games',
            subject: $game,
            context: [
                'tournament_id' => $tournament->id,
                'tournament_name' => $tournament->name,
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
                'group_id' => $group->id,
                'group_name' => $group->name,
                'game_id' => $game->id,
            ],
        ));

        $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');

        $this->getJson("/api/v1/audit-logs?tournament_id={$tournament->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);

        $this->getJson("/api/v1/audit-logs?competition_id={$competition->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);

        $this->getJson("/api/v1/audit-logs?group_id={$group->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);

        $this->getJson("/api/v1/audit-logs?game_id={$game->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);
    }

    public function test_filters_by_date_range(): void
    {
        $inside = $this->createAuditActivity(
            AuditAction::GROUPS_REGENERATED,
            'groups',
            Carbon::parse('2026-07-12 15:00:00'),
        );

        $this->createAuditActivity(
            AuditAction::BRACKET_CREATED,
            'bracket',
            Carbon::parse('2026-07-01 15:00:00'),
        );

        $response = $this->getJson(
            '/api/v1/audit-logs?from=2026-07-10&to=2026-07-15',
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame([$inside->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_filters_by_subject_type_and_subject_id(): void
    {
        $competition = $this->createCompetition();
        $match = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
        ));

        $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');

        $response = $this->getJson(
            "/api/v1/audit-logs?subject_type=competition&subject_id={$competition->id}",
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_combined_filters_narrow_results(): void
    {
        $competition = $this->createCompetition();
        $match = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
            context: [
                'tournament_id' => $competition->tournament_id,
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
            ],
        ));

        app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $this->createCompetition(name: 'Otra comp'),
        ));

        $response = $this->getJson(
            '/api/v1/audit-logs?'
            .http_build_query([
                'action' => AuditAction::GROUPS_REGENERATED->value,
                'log_name' => 'groups',
                'competition_id' => $competition->id,
            ]),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_search_by_actor_name_and_context(): void
    {
        $user = User::factory()->create([
            'keycloak_id' => 'audit-search-actor',
            'name' => 'Carlos Pérez',
            'email' => 'carlos@example.com',
        ]);

        Auth::setUser($user);

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $this->createCompetition(name: 'Primera Caballeros'),
            context: [
                'tournament_name' => 'Torneo Apertura',
                'competition_name' => 'Primera Caballeros',
                'group_name' => 'Grupo Norte',
            ],
        ));

        Auth::logout();
        $this->createAuditActivity(AuditAction::BRACKET_CREATED, 'bracket');

        $this->getJson('/api/v1/audit-logs?search=Carlos', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);

        $this->getJson('/api/v1/audit-logs?search=Caballeros', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);

        $this->getJson('/api/v1/audit-logs?search=Grupo+Norte', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id);
    }

    public function test_invalid_filters_return_422(): void
    {
        $this->getJson('/api/v1/audit-logs?action=invalid.action', $this->adminHeaders())
            ->assertUnprocessable();

        $this->getJson('/api/v1/audit-logs?log_name=unknown', $this->adminHeaders())
            ->assertUnprocessable();

        $this->getJson('/api/v1/audit-logs?subject_type=App\\Models\\Competition', $this->adminHeaders())
            ->assertUnprocessable();

        $this->getJson('/api/v1/audit-logs?per_page=101', $this->adminHeaders())
            ->assertUnprocessable();

        $this->getJson('/api/v1/audit-logs?from=2026-07-10&to=2026-07-01', $this->adminHeaders())
            ->assertUnprocessable();
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return $this->keycloakAuthHeaders(['admin']);
    }

    private function createAuditActivity(
        AuditAction $action,
        string $logName,
        ?Carbon $createdAt = null,
    ): Activity {
        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: $action,
            logName: $logName,
            subject: $this->createCompetition(),
        ));

        if ($createdAt !== null) {
            $activity->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $activity->fresh();
    }

    private function createCompetition(string $name = 'Comp Test'): Competition
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        return Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => $name,
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);
    }
}
