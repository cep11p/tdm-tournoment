<?php

namespace Database\Seeders\Support;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Group\CreateGroupAction;
use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Actions\Group\PersistGroupEntryAction;
use App\Actions\Registration\RegisterCompetitionEntryAction;
use App\Actions\Tournament\CreateTournamentAction;
use App\Enums\TournamentStatus;
use App\Models\Category;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Support\Carbon;

final class DemoScenarioRunner
{
    public const TOURNAMENT_ACTIVE = 'Torneo Demo TDM';

    public const TOURNAMENT_ARCHIVED = 'Torneo Demo TDM — Finalizado';

    public function __construct(
        private readonly CreateTournamentAction $createTournament,
        private readonly CreateCompetitionAction $createCompetition,
        private readonly RegisterCompetitionEntryAction $registerEntry,
        private readonly CreateGroupAction $createGroup,
        private readonly PersistGroupEntryAction $persistGroupEntry,
        private readonly GenerateGroupRoundRobinGamesAction $generateRoundRobin,
    ) {}

    public function findOrCreateTournament(string $name, TournamentStatus $status = TournamentStatus::InProgress): Tournament
    {
        $existing = Tournament::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        return ($this->createTournament)([
            'name' => $name,
            'location' => 'Club Demo TDM',
            'start_date' => Carbon::today()->toDateString(),
            'status' => $status,
        ]);
    }

    public function findOrCreateCompetition(Tournament $tournament, DemoCompetitionConfig $config): Competition
    {
        $existing = Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $config->name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $categoryId = Category::query()
            ->where('slug', $config->categorySlug)
            ->value('id');

        if ($categoryId === null) {
            throw new \RuntimeException(sprintf('No existe la categoría %s.', $config->categorySlug));
        }

        return ($this->createCompetition)($config->toPayload($tournament->id, (int) $categoryId));
    }

    public function competitionHasSchedule(Competition $competition): bool
    {
        return Game::query()
            ->where('competition_id', $competition->id)
            ->exists();
    }

    public function competitionHasBracket(Competition $competition): bool
    {
        return $competition->brackets()->exists();
    }

    /**
     * @param  list<string>  $nicknames
     * @return array<string, CompetitionEntry>
     */
    public function registerSinglesByNicknames(Competition $competition, array $nicknames): array
    {
        $entries = [];

        foreach ($nicknames as $nickname) {
            $player = DemoPlayerCatalog::byNickname($nickname);
            $entries[$nickname] = $this->registerSinglesPlayer($competition, $player);
        }

        return $entries;
    }

    public function registerSinglesPlayer(Competition $competition, Player $player): CompetitionEntry
    {
        $existingMember = $competition->entries()
            ->whereHas('members', fn ($query) => $query->where('player_id', $player->id))
            ->first();

        if ($existingMember !== null) {
            return $existingMember;
        }

        return ($this->registerEntry)([
            'competition_id' => $competition->id,
            'player_id' => $player->id,
        ]);
    }

    /**
     * @return array<int, CompetitionEntry>
     */
    public function registerAllSinglesPlayers(Competition $competition): array
    {
        $entries = [];

        foreach (DemoPlayerCatalog::definitions() as $seed => $definition) {
            $entries[$seed] = $this->registerSinglesPlayer(
                $competition,
                DemoPlayerCatalog::bySeed($seed),
            );
        }

        return $entries;
    }

    /**
     * @return array<int, CompetitionEntry>
     */
    public function registerDoublesPairs(Competition $competition): array
    {
        $entries = [];

        foreach (DemoPlayerCatalog::DOUBLES_PAIRS as $pair) {
            $playerIds = array_map(
                fn (string $nickname): int => DemoPlayerCatalog::byNickname($nickname)->id,
                $pair['nicknames'],
            );

            $existing = $this->findDoublesEntryByPlayerIds($competition, $playerIds);

            $entries[$pair['seed']] = $existing ?? ($this->registerEntry)([
                'competition_id' => $competition->id,
                'player_ids' => $playerIds,
            ]);
        }

        return $entries;
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function findDoublesEntryByPlayerIds(Competition $competition, array $playerIds): ?CompetitionEntry
    {
        sort($playerIds);

        return $competition->entries()
            ->with('members')
            ->get()
            ->first(function (CompetitionEntry $entry) use ($playerIds): bool {
                $memberIds = $entry->members->pluck('player_id')->sort()->values()->all();

                return $memberIds === $playerIds;
            });
    }

    public function findOrCreateGroup(Competition $competition, string $name): Group
    {
        $existing = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ($this->createGroup)([
            'competition_id' => $competition->id,
            'name' => $name,
        ]);
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    public function assignEntriesToGroup(Group $group, array $entries): void
    {
        foreach ($entries as $entry) {
            $alreadyAssigned = Group::query()
                ->where('competition_id', $group->competition_id)
                ->whereHas('groupEntries', fn ($query) => $query->where('competition_entry_id', $entry->id))
                ->exists();

            if ($alreadyAssigned) {
                continue;
            }

            ($this->persistGroupEntry)($group, $entry);
        }
    }

    public function generateGroupRoundRobinIfNeeded(Group $group): void
    {
        if ($group->games()->exists()) {
            return;
        }

        ($this->generateRoundRobin)($group);
    }

    /**
     * @param  array<int, CompetitionEntry>  $entriesBySeed
     * @param  list<string>  $nicknames
     * @return list<CompetitionEntry>
     */
    public function entriesForNicknames(array $entriesBySeed, array $nicknames): array
    {
        return array_map(
            fn (string $nickname): CompetitionEntry => $entriesBySeed[DemoPlayerCatalog::seedForNickname($nickname)],
            $nicknames,
        );
    }
}
