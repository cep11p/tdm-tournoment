<?php

namespace Database\Seeders;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Group\CreateGroupAction;
use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Actions\Player\CreatePlayerAction;
use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Actions\Tournament\CreateTournamentAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoTournamentSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const PLAYER_NAMES = [
        'Carlos Perez',
        'Juan Gomez',
        'Pedro Ruiz',
        'Marcos Diaz',
        'Luis Lopez',
        'Martin Castro',
        'Diego Silva',
        'Nicolas Torres',
    ];

    /**
     * @var array<int, string>
     */
    private const COMPETITION_NAMES = [
        'Primera',
        'Segunda',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('DemoTournamentSeeder está pensado solo para desarrollo/local.');

            return;
        }

        $createTournament = app(CreateTournamentAction::class);
        $createCompetition = app(CreateCompetitionAction::class);
        $createPlayer = app(CreatePlayerAction::class);
        $registerPlayer = app(RegisterPlayerToCompetitionAction::class);
        $createGroup = app(CreateGroupAction::class);
        $assignPlayer = app(AssignPlayerToGroupAction::class);
        $generateRoundRobin = app(GenerateGroupRoundRobinGamesAction::class);

        $tournament = $this->findOrCreateTournament($createTournament);

        foreach (self::COMPETITION_NAMES as $competitionName) {
            $competition = $this->findOrCreateCompetition($competitionName, $tournament, $createCompetition);
            $players = $this->findOrCreateCompetitionPlayers($competitionName, $createPlayer);

            $this->registerPlayersToCompetition($competition, $players, $registerPlayer);

            $groupA = $this->findOrCreateGroup($competition, 'Grupo A', $createGroup);
            $groupB = $this->findOrCreateGroup($competition, 'Grupo B', $createGroup);

            $this->assignPlayersToGroup($competition, $groupA, array_slice($players, 0, 4), $assignPlayer);
            $this->assignPlayersToGroup($competition, $groupB, array_slice($players, 4, 4), $assignPlayer);

            if (! $groupA->games()->exists()) {
                ($generateRoundRobin)($groupA);
            }

            if (! $groupB->games()->exists()) {
                ($generateRoundRobin)($groupB);
            }
        }
    }

    private function findOrCreateTournament(CreateTournamentAction $createTournament): Tournament
    {
        $existingTournament = Tournament::query()
            ->where('name', 'Torneo Demo')
            ->first();

        if ($existingTournament !== null) {
            return $existingTournament;
        }

        return ($createTournament)([
            'name' => 'Torneo Demo',
            'location' => 'Club Demo',
            'start_date' => now()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);
    }

    private function findOrCreateCompetition(
        string $competitionName,
        Tournament $tournament,
        CreateCompetitionAction $createCompetition
    ): Competition {
        $existingCompetition = Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $competitionName)
            ->first();

        if ($existingCompetition !== null) {
            return $existingCompetition;
        }

        return ($createCompetition)([
            'tournament_id' => $tournament->id,
            'name' => $competitionName,
            'type' => CompetitionType::Singles,
            'category' => Str::lower($competitionName),
            'format' => CompetitionFormat::Manual,
            'points_per_set' => 11,
            'group_stage_best_of' => 3,
            'knockout_stage_best_of' => 3,
            'semifinal_best_of' => 3,
            'final_best_of' => 3,
        ]);
    }

    /**
     * @return array<int, Player>
     */
    private function findOrCreateCompetitionPlayers(
        string $competitionName,
        CreatePlayerAction $createPlayer
    ): array {
        $players = [];
        $competitionSlug = Str::slug($competitionName);

        foreach (self::PLAYER_NAMES as $index => $playerName) {
            [$firstName, $lastName] = explode(' ', $playerName, 2);
            $nickname = sprintf('demo-%s-%d', $competitionSlug, $index + 1);

            $player = Player::query()
                ->where('nickname', $nickname)
                ->first();

            if ($player === null) {
                $player = ($createPlayer)([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nickname' => $nickname,
                ]);
            }

            $players[] = $player;
        }

        return $players;
    }

    /**
     * @param  array<int, Player>  $players
     */
    private function registerPlayersToCompetition(
        Competition $competition,
        array $players,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): void {
        foreach ($players as $player) {
            $alreadyRegistered = Registration::query()
                ->where('competition_id', $competition->id)
                ->where('player_id', $player->id)
                ->exists();

            if (! $alreadyRegistered) {
                ($registerPlayer)([
                    'competition_id' => $competition->id,
                    'player_id' => $player->id,
                ]);
            }
        }
    }

    private function findOrCreateGroup(
        Competition $competition,
        string $groupName,
        CreateGroupAction $createGroup
    ): Group {
        $existingGroup = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', $groupName)
            ->first();

        if ($existingGroup !== null) {
            return $existingGroup;
        }

        return ($createGroup)([
            'competition_id' => $competition->id,
            'name' => $groupName,
        ]);
    }

    /**
     * @param  array<int, Player>  $players
     */
    private function assignPlayersToGroup(
        Competition $competition,
        Group $group,
        array $players,
        AssignPlayerToGroupAction $assignPlayer
    ): void {
        foreach ($players as $player) {
            $alreadyAssignedToThisGroup = GroupPlayer::query()
                ->where('group_id', $group->id)
                ->where('player_id', $player->id)
                ->exists();

            if ($alreadyAssignedToThisGroup) {
                continue;
            }

            $alreadyAssignedInCompetition = GroupPlayer::query()
                ->where('player_id', $player->id)
                ->whereHas('group', fn ($query) => $query->where('competition_id', $competition->id))
                ->exists();

            if (! $alreadyAssignedInCompetition) {
                ($assignPlayer)([
                    'group_id' => $group->id,
                    'player_id' => $player->id,
                ]);
            }
        }
    }
}
