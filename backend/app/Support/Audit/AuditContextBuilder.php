<?php

namespace App\Support\Audit;

use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\Tournament;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Support\Collection;

final class AuditContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function fromTournament(Tournament $tournament): array
    {
        return [
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPlayer(Player $player): array
    {
        return [
            'player_id' => $player->id,
            'player_name' => self::playerDisplayName($player),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRegistrationContext(
        Competition $competition,
        Player $player,
        ?CompetitionEntry $entry = null,
    ): array {
        $competition->loadMissing('tournament');

        $context = self::fromCompetition($competition);

        return array_merge($context, [
            'registration_id' => $entry?->id,
            'competition_entry_id' => $entry?->id,
            'player_id' => $player->id,
            'player_name' => self::playerDisplayName($player),
        ]);
    }

    /**
     * @param  Collection<int, Player>  $players
     * @return array<string, mixed>
     */
    public static function fromDoublesRegistrationContext(
        Competition $competition,
        CompetitionEntry $entry,
        Collection $players,
    ): array {
        $competition->loadMissing('tournament');

        $memberIds = $players->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $memberNames = $players
            ->map(fn (Player $player): ?string => self::playerDisplayName($player))
            ->filter()
            ->values()
            ->all();

        return array_merge(self::fromCompetition($competition), [
            'registration_id' => $entry->id,
            'competition_entry_id' => $entry->id,
            'member_ids' => $memberIds,
            'member_names' => $memberNames,
            'display_name' => CompetitionEntryDisplayName::for($entry),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromCompetition(Competition $competition, ?int $bracketId = null): array
    {
        $competition->loadMissing('tournament');

        return self::baseContext(
            tournamentId: $competition->tournament_id,
            tournamentName: $competition->tournament?->name,
            competitionId: $competition->id,
            competitionName: $competition->name,
            bracketId: $bracketId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromGroup(Group $group, ?int $bracketId = null): array
    {
        $group->loadMissing('competition.tournament');

        return self::baseContext(
            tournamentId: $group->competition?->tournament_id,
            tournamentName: $group->competition?->tournament?->name,
            competitionId: $group->competition_id,
            competitionName: $group->competition?->name,
            groupId: $group->id,
            groupName: $group->name,
            bracketId: $bracketId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromBracket(Bracket $bracket): array
    {
        $bracket->loadMissing('competition.tournament');

        return self::baseContext(
            tournamentId: $bracket->competition?->tournament_id,
            tournamentName: $bracket->competition?->tournament?->name,
            competitionId: $bracket->competition_id,
            competitionName: $bracket->competition?->name,
            bracketId: $bracket->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromGame(Game $game): array
    {
        $game->loadMissing([
            'competition.tournament',
            'group',
            'bracket',
            ...Game::DISPLAY_RELATIONS,
        ]);

        $context = self::baseContext(
            tournamentId: $game->competition?->tournament_id,
            tournamentName: $game->competition?->tournament?->name,
            competitionId: $game->competition_id,
            competitionName: $game->competition?->name,
            groupId: $game->group_id,
            groupName: $game->group?->name,
            bracketId: $game->bracket_id,
            gameId: $game->id,
        );

        return array_merge($context, [
            'player1_id' => $game->singlesPlayer1Id(),
            'player1_name' => self::playerDisplayName($game->singlesPlayer1()),
            'player2_id' => $game->singlesPlayer2Id(),
            'player2_name' => self::playerDisplayName($game->singlesPlayer2()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseContext(
        ?int $tournamentId = null,
        ?string $tournamentName = null,
        ?int $competitionId = null,
        ?string $competitionName = null,
        ?int $groupId = null,
        ?string $groupName = null,
        ?int $bracketId = null,
        ?int $gameId = null,
    ): array {
        return [
            'tournament_id' => $tournamentId,
            'tournament_name' => $tournamentName,
            'competition_id' => $competitionId,
            'competition_name' => $competitionName,
            'group_id' => $groupId,
            'group_name' => $groupName,
            'bracket_id' => $bracketId,
            'game_id' => $gameId,
        ];
    }

    private static function playerDisplayName(?Player $player): ?string
    {
        if ($player === null) {
            return null;
        }

        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : null;
    }
}
