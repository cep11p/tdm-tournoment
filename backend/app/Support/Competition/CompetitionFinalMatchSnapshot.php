<?php

namespace App\Support\Competition;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\Game;
use App\Models\TeamTie;
use Illuminate\Support\Collection;

final class CompetitionFinalMatchSnapshot
{
    public function __construct(
        public ?int $bracketRound,
        public int $bracketMatch,
        public string $purpose,
        public bool $isBye,
        public string $status,
        public ?string $round,
        public ?int $entry1Id,
        public ?int $entry2Id,
        public ?int $winnerEntryId,
    ) {}

    public static function fromGame(Game $game): self
    {
        $purpose = $game->bracket_purpose instanceof BracketGamePurpose
            ? $game->bracket_purpose
            : BracketGamePurpose::from((string) ($game->bracket_purpose ?? BracketGamePurpose::Main->value));

        $status = $game->status instanceof GameStatus
            ? $game->status->value
            : (string) $game->status;

        return new self(
            bracketRound: $game->bracket_round !== null ? (int) $game->bracket_round : null,
            bracketMatch: (int) ($game->bracket_match ?? 1),
            purpose: $purpose->value,
            isBye: (bool) $game->is_bye,
            status: $status,
            round: $game->round !== null ? (string) $game->round : null,
            entry1Id: $game->entry1_id !== null ? (int) $game->entry1_id : null,
            entry2Id: $game->entry2_id !== null ? (int) $game->entry2_id : null,
            winnerEntryId: $game->winner_entry_id !== null ? (int) $game->winner_entry_id : null,
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

        return new self(
            bracketRound: $teamTie->bracket_round !== null ? (int) $teamTie->bracket_round : null,
            bracketMatch: (int) ($teamTie->bracket_match ?? 1),
            purpose: $purpose->value,
            isBye: (bool) $teamTie->is_bye,
            status: $status,
            round: $teamTie->round !== null ? (string) $teamTie->round : null,
            entry1Id: $teamTie->entry1_id !== null ? (int) $teamTie->entry1_id : null,
            entry2Id: $teamTie->entry2_id !== null ? (int) $teamTie->entry2_id : null,
            winnerEntryId: $teamTie->winner_entry_id !== null ? (int) $teamTie->winner_entry_id : null,
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

    public function isMain(): bool
    {
        return $this->purpose === BracketGamePurpose::Main->value;
    }

    public function isThirdPlace(): bool
    {
        return $this->purpose === BracketGamePurpose::ThirdPlace->value;
    }

    public function isFinishedWithWinner(): bool
    {
        if ($this->winnerEntryId === null) {
            return false;
        }

        return $this->status === GameStatus::Finished->value
            || $this->status === TeamTieStatus::Finished->value;
    }

    public function loserEntryId(): ?int
    {
        if ($this->isBye || $this->winnerEntryId === null || $this->entry1Id === null || $this->entry2Id === null) {
            return null;
        }

        if ($this->winnerEntryId === $this->entry1Id) {
            return $this->entry2Id;
        }

        if ($this->winnerEntryId === $this->entry2Id) {
            return $this->entry1Id;
        }

        return null;
    }
}
