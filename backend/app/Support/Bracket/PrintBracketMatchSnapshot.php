<?php

namespace App\Support\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\BracketEntryOrigin;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Support\Collection;

final class PrintBracketMatchSnapshot
{
    /**
     * @param  array{competition_entry_id: int, display_name: string, group_origin: array{group_id: int, group_name: string, position: int}|null}|null  $side1
     * @param  array{competition_entry_id: int, display_name: string, group_origin: array{group_id: int, group_name: string, position: int}|null}|null  $side2
     * @param  array{competition_entry_id: int, display_name: string, group_origin: array{group_id: int, group_name: string, position: int}|null}|null  $winner
     */
    public function __construct(
        public ?int $bracketRound,
        public int $bracketMatch,
        public string $purpose,
        public bool $isBye,
        public string $status,
        public ?string $roundLabel,
        public ?array $side1,
        public ?array $side2,
        public ?array $winner,
        public ?int $winnerEntryId,
        public ?int $entry1Id,
        public ?int $entry2Id,
    ) {}

    /**
     * @param  Collection<int, BracketEntryOrigin>  $origins
     */
    public static function fromGame(Game $game, Collection $origins): self
    {
        $purpose = $game->bracket_purpose instanceof BracketGamePurpose
            ? $game->bracket_purpose
            : BracketGamePurpose::from((string) ($game->bracket_purpose ?? BracketGamePurpose::Main->value));

        $status = $game->status instanceof GameStatus
            ? $game->status->value
            : (string) $game->status;

        $side1 = self::side($game->entry1, $origins);
        $side2 = $game->is_bye ? null : self::side($game->entry2, $origins);
        $winner = self::side($game->winnerEntry, $origins);

        if ($winner === null && $game->is_bye) {
            $winner = $side1;
        }

        return new self(
            bracketRound: $game->bracket_round !== null ? (int) $game->bracket_round : null,
            bracketMatch: (int) ($game->bracket_match ?? 1),
            purpose: $purpose->value,
            isBye: (bool) $game->is_bye,
            status: $status,
            roundLabel: $game->round !== null ? (string) $game->round : null,
            side1: $side1,
            side2: $side2,
            winner: $winner,
            winnerEntryId: $game->winner_entry_id !== null ? (int) $game->winner_entry_id : null,
            entry1Id: $game->entry1_id !== null ? (int) $game->entry1_id : null,
            entry2Id: $game->entry2_id !== null ? (int) $game->entry2_id : null,
        );
    }

    /**
     * @param  Collection<int, BracketEntryOrigin>  $origins
     */
    public static function fromTeamTie(TeamTie $teamTie, Collection $origins): self
    {
        $purpose = $teamTie->bracket_purpose instanceof BracketGamePurpose
            ? $teamTie->bracket_purpose
            : BracketGamePurpose::from((string) ($teamTie->bracket_purpose ?? BracketGamePurpose::Main->value));

        $status = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status->value
            : (string) $teamTie->status;

        $side1 = self::side($teamTie->entry1, $origins);
        $side2 = $teamTie->is_bye ? null : self::side($teamTie->entry2, $origins);
        $winner = self::side($teamTie->winnerEntry, $origins);

        if ($winner === null && $teamTie->is_bye) {
            $winner = $side1;
        }

        return new self(
            bracketRound: $teamTie->bracket_round !== null ? (int) $teamTie->bracket_round : null,
            bracketMatch: (int) ($teamTie->bracket_match ?? 1),
            purpose: $purpose->value,
            isBye: (bool) $teamTie->is_bye,
            status: $status,
            roundLabel: $teamTie->round !== null ? (string) $teamTie->round : null,
            side1: $side1,
            side2: $side2,
            winner: $winner,
            winnerEntryId: $teamTie->winner_entry_id !== null ? (int) $teamTie->winner_entry_id : null,
            entry1Id: $teamTie->entry1_id !== null ? (int) $teamTie->entry1_id : null,
            entry2Id: $teamTie->entry2_id !== null ? (int) $teamTie->entry2_id : null,
        );
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  Collection<int, BracketEntryOrigin>  $origins
     * @return list<self>
     */
    public static function fromGames(Collection $games, Collection $origins): array
    {
        return $games
            ->map(fn (Game $game): self => self::fromGame($game, $origins))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TeamTie>  $teamTies
     * @param  Collection<int, BracketEntryOrigin>  $origins
     * @return list<self>
     */
    public static function fromTeamTies(Collection $teamTies, Collection $origins): array
    {
        return $teamTies
            ->map(fn (TeamTie $teamTie): self => self::fromTeamTie($teamTie, $origins))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BracketEntryOrigin>  $origins
     * @return array{competition_entry_id: int, display_name: string, group_origin: array{group_id: int, group_name: string, position: int}|null}|null
     */
    public static function side(?CompetitionEntry $entry, Collection $origins): ?array
    {
        if ($entry === null) {
            return null;
        }

        $origin = $origins->get((int) $entry->id);

        return [
            'competition_entry_id' => (int) $entry->id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
            'group_origin' => $origin instanceof BracketEntryOrigin
                ? $origin->toSidePayload()
                : null,
        ];
    }
}
