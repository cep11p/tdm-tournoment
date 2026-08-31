<?php

namespace App\Actions\Bracket;

use App\Actions\TeamTie\MaterializeTeamTieGamesAction;
use App\Enums\BracketGamePurpose;
use App\Enums\TeamTieStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use Illuminate\Validation\ValidationException;

final class CreateBracketTeamTieAction
{
    public function __construct(
        private readonly MaterializeTeamTieGamesAction $materializeTeamTieGames,
    ) {}

    public function __invoke(
        Competition $competition,
        Bracket $bracket,
        int $entry1Id,
        ?int $entry2Id,
        ?int $bracketRound,
        int $bracketMatch,
        BracketGamePurpose $bracketPurpose,
        string $roundLabel,
    ): TeamTie {
        $competition->loadMissing('teamTieFormat');

        TeamCompetitionSchedulingGuard::ensureFormatConfigured($competition);

        /** @var TeamTieFormat $format */
        $format = $competition->teamTieFormat;

        if ($entry2Id === null) {
            return TeamTie::query()->create([
                'competition_id' => $competition->id,
                'group_id' => null,
                'bracket_id' => $bracket->id,
                'entry1_id' => $entry1Id,
                'entry2_id' => null,
                'winner_entry_id' => $entry1Id,
                'team_tie_format_id' => $format->id,
                'victories_required' => (int) $format->victories_required,
                'format_name' => $format->name,
                'status' => TeamTieStatus::Finished,
                'is_bye' => true,
                'bracket_round' => $bracketRound,
                'bracket_match' => $bracketMatch,
                'bracket_purpose' => $bracketPurpose,
                'round' => $roundLabel,
                'finished_at' => now(),
            ]);
        }

        $teamTie = TeamTie::query()->create([
            'competition_id' => $competition->id,
            'group_id' => null,
            'bracket_id' => $bracket->id,
            'entry1_id' => $entry1Id,
            'entry2_id' => $entry2Id,
            'winner_entry_id' => null,
            'team_tie_format_id' => $format->id,
            'victories_required' => (int) $format->victories_required,
            'format_name' => $format->name,
            'status' => TeamTieStatus::Pending,
            'is_bye' => false,
            'bracket_round' => $bracketRound,
            'bracket_match' => $bracketMatch,
            'bracket_purpose' => $bracketPurpose,
            'round' => $roundLabel,
        ]);

        ($this->materializeTeamTieGames)($teamTie, $format);

        return $teamTie;
    }

    public function createThirdPlace(
        Competition $competition,
        Bracket $bracket,
        int $entry1Id,
        int $entry2Id,
    ): TeamTie {
        if ($entry1Id === $entry2Id) {
            throw ValidationException::withMessages([
                'bracket' => ['Los participantes del partido por tercer puesto deben ser distintos.'],
            ]);
        }

        return ($this)(
            competition: $competition,
            bracket: $bracket,
            entry1Id: $entry1Id,
            entry2Id: $entry2Id,
            bracketRound: null,
            bracketMatch: 1,
            bracketPurpose: BracketGamePurpose::ThirdPlace,
            roundLabel: 'Tercer puesto',
        );
    }
}
