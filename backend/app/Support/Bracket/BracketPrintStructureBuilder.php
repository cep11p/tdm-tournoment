<?php

namespace App\Support\Bracket;

use App\Data\Bracket\PrintBracketData;
use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class BracketPrintStructureBuilder
{
    public const PLACEHOLDER_STATUS = 'not_created';

    /**
     * @param  list<PrintBracketMatchSnapshot>  $snapshots
     * @param  array{id: int, name: string}  $tournament
     */
    public function build(
        Bracket $bracket,
        Competition $competition,
        array $snapshots,
        array $tournament,
    ): PrintBracketData {
        $bracketSize = (int) $bracket->bracket_size;

        if ($bracketSize < 2 || ($bracketSize & ($bracketSize - 1)) !== 0) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene un tamaño válido para imprimir.'],
            ]);
        }

        [$mainByRound, $thirdPlaceSnapshot] = $this->indexSnapshots($snapshots);

        $roundsCount = BracketPodiumSupport::finalRound($bracket);
        $rounds = [];
        $previousMatches = [];

        for ($roundNumber = 1; $roundNumber <= $roundsCount; $roundNumber++) {
            $matchesCount = (int) ($bracketSize / (2 ** $roundNumber));
            $label = $this->roundLabel($bracketSize, $roundNumber, $mainByRound);
            $matches = [];

            for ($matchNumber = 1; $matchNumber <= $matchesCount; $matchNumber++) {
                $snapshot = $mainByRound[$roundNumber][$matchNumber] ?? null;

                $matches[] = $snapshot !== null
                    ? $this->matchFromSnapshot($snapshot, $roundNumber, $matchNumber)
                    : $this->placeholderMatch($roundNumber, $matchNumber, $previousMatches);
            }

            $rounds[] = [
                'number' => $roundNumber,
                'label' => $label,
                'matches' => $matches,
            ];

            $previousMatches = $matches;
        }

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return new PrintBracketData(
            tournament: $tournament,
            competition: [
                'id' => (int) $competition->id,
                'name' => (string) $competition->name,
                'type' => $type->value,
            ],
            bracket: [
                'id' => (int) $bracket->id,
                'name' => (string) $bracket->name,
                'bracket_size' => $bracketSize,
                'byes_count' => (int) $bracket->byes_count,
                'rounds_count' => $roundsCount,
            ],
            rounds: $rounds,
            thirdPlace: $this->thirdPlace(
                $bracket,
                $competition,
                $rounds,
                $thirdPlaceSnapshot,
            ),
            champion: $this->champion($rounds),
        );
    }

    /**
     * @param  list<PrintBracketMatchSnapshot>  $snapshots
     * @return array{0: array<int, array<int, PrintBracketMatchSnapshot>>, 1: PrintBracketMatchSnapshot|null}
     */
    private function indexSnapshots(array $snapshots): array
    {
        $mainByRound = [];
        $thirdPlace = null;

        foreach ($snapshots as $snapshot) {
            if ($snapshot->purpose === BracketGamePurpose::ThirdPlace->value) {
                $thirdPlace = $snapshot;

                continue;
            }

            if ($snapshot->bracketRound === null) {
                continue;
            }

            $mainByRound[$snapshot->bracketRound][$snapshot->bracketMatch] = $snapshot;
        }

        return [$mainByRound, $thirdPlace];
    }

    /**
     * @param  array<int, array<int, PrintBracketMatchSnapshot>>  $mainByRound
     */
    private function roundLabel(int $bracketSize, int $roundNumber, array $mainByRound): string
    {
        if ($roundNumber === 1) {
            $persisted = $mainByRound[1] ?? [];
            ksort($persisted);

            foreach ($persisted as $snapshot) {
                if ($snapshot->roundLabel !== null && $snapshot->roundLabel !== '') {
                    return $snapshot->roundLabel;
                }
            }
        }

        $playersInRound = (int) ($bracketSize / (2 ** ($roundNumber - 1)));

        return BracketSupport::roundLabelFor($playersInRound);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchFromSnapshot(
        PrintBracketMatchSnapshot $snapshot,
        int $roundNumber,
        int $matchNumber,
    ): array {
        [$source1, $source2] = $roundNumber > 1
            ? BracketPositionSupport::sourceMatchNumbers($matchNumber)
            : [null, null];

        return $this->matchPayload(
            roundNumber: $roundNumber,
            matchNumber: $matchNumber,
            exists: true,
            status: $snapshot->status,
            isBye: $snapshot->isBye,
            source1: $source1,
            source2: $source2,
            side1: $snapshot->side1,
            side2: $snapshot->side2,
            side1Placeholder: null,
            side2Placeholder: null,
            winner: $snapshot->winner,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $previousMatches
     * @return array<string, mixed>
     */
    private function placeholderMatch(int $roundNumber, int $matchNumber, array $previousMatches): array
    {
        [$source1, $source2] = BracketPositionSupport::sourceMatchNumbers($matchNumber);
        $previousByNumber = [];

        foreach ($previousMatches as $previous) {
            $previousByNumber[(int) $previous['match_number']] = $previous;
        }

        $side1 = $this->sideFromSource($previousByNumber[$source1] ?? null, $source1);
        $side2 = $this->sideFromSource($previousByNumber[$source2] ?? null, $source2);

        return $this->matchPayload(
            roundNumber: $roundNumber,
            matchNumber: $matchNumber,
            exists: false,
            status: self::PLACEHOLDER_STATUS,
            isBye: false,
            source1: $source1,
            source2: $source2,
            side1: $side1['side'],
            side2: $side2['side'],
            side1Placeholder: $side1['placeholder'],
            side2Placeholder: $side2['placeholder'],
            winner: null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $sourceMatch
     * @return array{side: array{competition_entry_id: int, display_name: string}|null, placeholder: string|null}
     */
    private function sideFromSource(?array $sourceMatch, int $sourceNumber): array
    {
        $winner = $sourceMatch['winner'] ?? null;

        if (is_array($winner) && isset($winner['competition_entry_id'], $winner['display_name'])) {
            return [
                'side' => [
                    'competition_entry_id' => (int) $winner['competition_entry_id'],
                    'display_name' => (string) $winner['display_name'],
                ],
                'placeholder' => null,
            ];
        }

        return [
            'side' => null,
            'placeholder' => sprintf('Ganador P%d', $sourceNumber),
        ];
    }

    /**
     * @param  list<array{number: int, label: string, matches: list<array<string, mixed>>}>  $rounds
     * @return array<string, mixed>|null
     */
    private function thirdPlace(
        Bracket $bracket,
        Competition $competition,
        array $rounds,
        ?PrintBracketMatchSnapshot $snapshot,
    ): ?array {
        $mode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) ($competition->third_place_mode ?? ThirdPlaceMode::None->value));

        if ($mode === ThirdPlaceMode::None) {
            return null;
        }

        $semifinalRound = BracketPodiumSupport::semifinalRound($bracket);

        if ($semifinalRound === null) {
            return null;
        }

        if ($mode === ThirdPlaceMode::Shared) {
            return [
                'mode' => ThirdPlaceMode::Shared->value,
                'label' => 'Tercer puesto compartido',
            ];
        }

        if (! $this->shouldShowPlayoffThirdPlace($rounds, $semifinalRound, $snapshot)) {
            return null;
        }

        $semifinalMatches = $this->roundMatches($rounds, $semifinalRound);

        if ($snapshot !== null) {
            return [
                'mode' => ThirdPlaceMode::Playoff->value,
                'label' => $snapshot->roundLabel ?: BracketGamePurpose::ThirdPlace->label(),
                'exists_in_database' => true,
                'status' => $snapshot->status,
                'is_bye' => $snapshot->isBye,
                'side1' => $snapshot->side1,
                'side2' => $snapshot->side2,
                'side1_placeholder' => null,
                'side2_placeholder' => null,
                'winner' => $snapshot->winner,
            ];
        }

        $side1 = $this->loserFromSemifinal($semifinalMatches[0] ?? null, 1);
        $side2 = $this->loserFromSemifinal($semifinalMatches[1] ?? null, 2);

        return [
            'mode' => ThirdPlaceMode::Playoff->value,
            'label' => BracketGamePurpose::ThirdPlace->label(),
            'exists_in_database' => false,
            'status' => self::PLACEHOLDER_STATUS,
            'is_bye' => false,
            'side1' => $side1['side'],
            'side2' => $side2['side'],
            'side1_placeholder' => $side1['placeholder'],
            'side2_placeholder' => $side2['placeholder'],
            'winner' => null,
        ];
    }

    /**
     * @param  list<array{number: int, label: string, matches: list<array<string, mixed>>}>  $rounds
     */
    private function shouldShowPlayoffThirdPlace(
        array $rounds,
        int $semifinalRound,
        ?PrintBracketMatchSnapshot $snapshot,
    ): bool {
        if ($snapshot !== null) {
            return true;
        }

        foreach ($this->roundMatches($rounds, $semifinalRound) as $match) {
            if (($match['is_bye'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{number: int, label: string, matches: list<array<string, mixed>>}>  $rounds
     * @return list<array<string, mixed>>
     */
    private function roundMatches(array $rounds, int $roundNumber): array
    {
        foreach ($rounds as $round) {
            if ((int) $round['number'] === $roundNumber) {
                return $round['matches'];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $semifinal
     * @return array{side: array{competition_entry_id: int, display_name: string}|null, placeholder: string|null}
     */
    private function loserFromSemifinal(?array $semifinal, int $semifinalNumber): array
    {
        $placeholder = sprintf('Perdedor semifinal %d', $semifinalNumber);

        if ($semifinal === null || ($semifinal['is_bye'] ?? false) === true) {
            return ['side' => null, 'placeholder' => $placeholder];
        }

        $winner = $semifinal['winner'] ?? null;
        $side1 = $semifinal['side1'] ?? null;
        $side2 = $semifinal['side2'] ?? null;

        if (! is_array($winner) || ! isset($winner['competition_entry_id'])) {
            return ['side' => null, 'placeholder' => $placeholder];
        }

        $winnerId = (int) $winner['competition_entry_id'];
        $loser = null;

        if (is_array($side1) && (int) ($side1['competition_entry_id'] ?? 0) === $winnerId) {
            $loser = is_array($side2) ? $side2 : null;
        } elseif (is_array($side2) && (int) ($side2['competition_entry_id'] ?? 0) === $winnerId) {
            $loser = is_array($side1) ? $side1 : null;
        }

        if ($loser === null) {
            return ['side' => null, 'placeholder' => $placeholder];
        }

        return ['side' => $loser, 'placeholder' => null];
    }

    /**
     * @param  list<array{number: int, label: string, matches: list<array<string, mixed>>}>  $rounds
     * @return array{competition_entry_id: int, display_name: string}|null
     */
    private function champion(array $rounds): ?array
    {
        $finalRound = $rounds[array_key_last($rounds)] ?? null;

        if ($finalRound === null) {
            return null;
        }

        $final = $finalRound['matches'][0] ?? null;
        $winner = $final['winner'] ?? null;

        if (! is_array($winner) || ! isset($winner['competition_entry_id'], $winner['display_name'])) {
            return null;
        }

        return [
            'competition_entry_id' => (int) $winner['competition_entry_id'],
            'display_name' => (string) $winner['display_name'],
        ];
    }

    /**
     * @param  array{competition_entry_id: int, display_name: string}|null  $side1
     * @param  array{competition_entry_id: int, display_name: string}|null  $side2
     * @param  array{competition_entry_id: int, display_name: string}|null  $winner
     * @return array<string, mixed>
     */
    private function matchPayload(
        int $roundNumber,
        int $matchNumber,
        bool $exists,
        string $status,
        bool $isBye,
        ?int $source1,
        ?int $source2,
        ?array $side1,
        ?array $side2,
        ?string $side1Placeholder,
        ?string $side2Placeholder,
        ?array $winner,
    ): array {
        return [
            'round_number' => $roundNumber,
            'match_number' => $matchNumber,
            'purpose' => BracketGamePurpose::Main->value,
            'exists_in_database' => $exists,
            'status' => $status,
            'is_bye' => $isBye,
            'source_match_1' => $source1,
            'source_match_2' => $source2,
            'side1' => $side1,
            'side2' => $side2,
            'side1_placeholder' => $side1Placeholder,
            'side2_placeholder' => $side2Placeholder,
            'winner' => $winner,
        ];
    }
}
