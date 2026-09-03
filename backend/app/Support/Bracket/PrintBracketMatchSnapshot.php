<?php

namespace App\Support\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Support\Collection;

final class PrintBracketMatchSnapshot
{
    /**
     * @param  array{competition_entry_id: int, display_name: string}|null  $side1
     * @param  array{competition_entry_id: int, display_name: string}|null  $side2
     * @param  array{competition_entry_id: int, display_name: string}|null  $winner
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

    public static function fromGame(Game $game): self
    {
        $purpose = $game->bracket_purpose instanceof BracketGamePurpose
            ? $game->bracket_purpose
            : BracketGamePurpose::from((string) ($game->bracket_purpose ?? BracketGamePurpose::Main->value));

        $status = $game->status instanceof GameStatus
            ? $game->status->value
            : (string) $game->status;

        $side1 = self::side($game->entry1);
        $side2 = $game->is_bye ? null : self::side($game->entry2);
        $winner = self::side($game->winnerEntry);

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

    public static function fromTeamTie(TeamTie $teamTie): self
    {
        $purpose = $teamTie->bracket_purpose instanceof BracketGamePurpose
            ? $teamTie->bracket_purpose
            : BracketGamePurpose::from((string) ($teamTie->bracket_purpose ?? BracketGamePurpose::Main->value));

        $status = $teamTie->status instanceof TeamTieStatus
            ? $teamTie->status->value
            : (string) $teamTie->status;

        $side1 = self::side($teamTie->entry1);
        $side2 = $teamTie->is_bye ? null : self::side($teamTie->entry2);
        $winner = self::side($teamTie->winnerEntry);

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
     * @return list<self>
     */
    public static function fromGames(Collection $games): array
    {
        return $games
            ->map(fn (Game $game): self => self::fromGame($game))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TeamTie>  $teamTies
     * @return list<self>
     */
    public static function fromTeamTies(Collection $teamTies): array
    {
        return $teamTies
            ->map(fn (TeamTie $teamTie): self => self::fromTeamTie($teamTie))
            ->values()
            ->all();
    }

    /**
     * @return array{competition_entry_id: int, display_name: string}|null
     */
    public static function side(?CompetitionEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'competition_entry_id' => (int) $entry->id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
        ];
    }
}
