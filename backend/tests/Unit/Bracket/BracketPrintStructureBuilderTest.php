<?php

namespace Tests\Unit\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Competition;
use App\Support\Bracket\BracketPrintStructureBuilder;
use App\Support\Bracket\BracketSupport;
use App\Support\Bracket\PrintBracketMatchSnapshot;
use Tests\TestCase;

class BracketPrintStructureBuilderTest extends TestCase
{
    private BracketPrintStructureBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new BracketPrintStructureBuilder;
    }

    public function test_size_two_has_one_final_round(): void
    {
        $payload = $this->buildEmpty(size: 2);

        $this->assertSame(1, $payload['bracket']['rounds_count']);
        $this->assertCount(1, $payload['rounds']);
        $this->assertSame('Final', $payload['rounds'][0]['label']);
        $this->assertCount(1, $payload['rounds'][0]['matches']);
        $this->assertNull($payload['third_place']);
        $this->assertNull($payload['champion']);
    }

    public function test_size_four_has_semifinal_and_final(): void
    {
        $payload = $this->buildEmpty(size: 4);

        $this->assertSame(2, $payload['bracket']['rounds_count']);
        $this->assertSame(['Semifinal', 'Final'], array_column($payload['rounds'], 'label'));
        $this->assertCount(2, $payload['rounds'][0]['matches']);
        $this->assertCount(1, $payload['rounds'][1]['matches']);
    }

    public function test_size_eight_round_and_match_counts(): void
    {
        $payload = $this->buildEmpty(size: 8);

        $this->assertSame(3, $payload['bracket']['rounds_count']);
        $this->assertSame(
            ['Cuartos de final', 'Semifinal', 'Final'],
            array_column($payload['rounds'], 'label'),
        );
        $this->assertCount(4, $payload['rounds'][0]['matches']);
        $this->assertCount(2, $payload['rounds'][1]['matches']);
        $this->assertCount(1, $payload['rounds'][2]['matches']);
    }

    public function test_size_sixteen_round_and_match_counts(): void
    {
        $payload = $this->buildEmpty(size: 16);

        $this->assertSame(4, $payload['bracket']['rounds_count']);
        $this->assertSame(
            ['8vos de final', 'Cuartos de final', 'Semifinal', 'Final'],
            array_column($payload['rounds'], 'label'),
        );
        $this->assertCount(8, $payload['rounds'][0]['matches']);
        $this->assertCount(4, $payload['rounds'][1]['matches']);
        $this->assertCount(2, $payload['rounds'][2]['matches']);
        $this->assertCount(1, $payload['rounds'][3]['matches']);
    }

    public function test_source_mapping_for_future_rounds(): void
    {
        $payload = $this->buildEmpty(size: 8);
        $semifinals = $payload['rounds'][1]['matches'];
        $final = $payload['rounds'][2]['matches'][0];

        $this->assertSame(1, $semifinals[0]['source_match_1']);
        $this->assertSame(2, $semifinals[0]['source_match_2']);
        $this->assertSame(3, $semifinals[1]['source_match_1']);
        $this->assertSame(4, $semifinals[1]['source_match_2']);
        $this->assertSame(1, $final['source_match_1']);
        $this->assertSame(2, $final['source_match_2']);
    }

    public function test_only_round_one_persisted_creates_future_placeholders(): void
    {
        $payload = $this->build(size: 8, snapshots: [
            $this->pendingMatch(round: 1, match: 1, side1Id: 1, side2Id: 2),
            $this->pendingMatch(round: 1, match: 2, side1Id: 3, side2Id: 4),
            $this->pendingMatch(round: 1, match: 3, side1Id: 5, side2Id: 6),
            $this->pendingMatch(round: 1, match: 4, side1Id: 7, side2Id: 8),
        ]);

        foreach ($payload['rounds'][0]['matches'] as $match) {
            $this->assertTrue($match['exists_in_database']);
            $this->assertSame(GameStatus::Pending->value, $match['status']);
            $this->assertNull($match['source_match_1']);
            $this->assertNull($match['side1_placeholder']);
        }

        $semifinal = $payload['rounds'][1]['matches'][0];
        $this->assertFalse($semifinal['exists_in_database']);
        $this->assertSame(BracketPrintStructureBuilder::PLACEHOLDER_STATUS, $semifinal['status']);
        $this->assertNull($semifinal['side1']);
        $this->assertNull($semifinal['side2']);
        $this->assertSame('Ganador P1', $semifinal['side1_placeholder']);
        $this->assertSame('Ganador P2', $semifinal['side2_placeholder']);

        $final = $payload['rounds'][2]['matches'][0];
        $this->assertFalse($final['exists_in_database']);
        $this->assertSame('Ganador P1', $final['side1_placeholder']);
        $this->assertSame('Ganador P2', $final['side2_placeholder']);
    }

    public function test_hydrates_known_winner_into_future_round(): void
    {
        $payload = $this->build(size: 8, snapshots: [
            $this->finishedMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, winnerId: 1, winnerName: 'Carlos Perez'),
            $this->pendingMatch(round: 1, match: 2, side1Id: 3, side2Id: 4),
            $this->pendingMatch(round: 1, match: 3, side1Id: 5, side2Id: 6),
            $this->pendingMatch(round: 1, match: 4, side1Id: 7, side2Id: 8),
        ]);

        $semifinal = $payload['rounds'][1]['matches'][0];
        $this->assertFalse($semifinal['exists_in_database']);
        $this->assertSame(1, $semifinal['side1']['competition_entry_id']);
        $this->assertSame('Carlos Perez', $semifinal['side1']['display_name']);
        $this->assertNull($semifinal['side1_placeholder']);
        $this->assertNull($semifinal['side2']);
        $this->assertSame('Ganador P2', $semifinal['side2_placeholder']);
    }

    public function test_bye_hydrates_winner_into_next_round(): void
    {
        $payload = $this->build(size: 8, snapshots: [
            $this->byeMatch(round: 1, match: 1, entryId: 1, name: 'Carlos Perez'),
            $this->pendingMatch(round: 1, match: 2, side1Id: 3, side2Id: 4),
            $this->pendingMatch(round: 1, match: 3, side1Id: 5, side2Id: 6),
            $this->pendingMatch(round: 1, match: 4, side1Id: 7, side2Id: 8),
        ]);

        $bye = $payload['rounds'][0]['matches'][0];
        $this->assertTrue($bye['is_bye']);
        $this->assertSame('Carlos Perez', $bye['side1']['display_name']);
        $this->assertNull($bye['side2']);
        $this->assertSame('Carlos Perez', $bye['winner']['display_name']);

        $semifinal = $payload['rounds'][1]['matches'][0];
        $this->assertSame('Carlos Perez', $semifinal['side1']['display_name']);
        $this->assertNull($semifinal['side1_placeholder']);
        $this->assertSame('Ganador P2', $semifinal['side2_placeholder']);
    }

    public function test_persisted_next_round_replaces_placeholders(): void
    {
        $payload = $this->build(size: 4, snapshots: [
            $this->finishedMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, winnerId: 1, winnerName: 'Carlos Perez'),
            $this->finishedMatch(round: 1, match: 2, side1Id: 3, side2Id: 4, winnerId: 3, winnerName: 'Juan Gomez'),
            $this->pendingMatch(round: 2, match: 1, side1Id: 1, side2Id: 3, side1Name: 'Carlos Perez', side2Name: 'Juan Gomez', label: 'Final'),
        ]);

        $final = $payload['rounds'][1]['matches'][0];
        $this->assertTrue($final['exists_in_database']);
        $this->assertSame(GameStatus::Pending->value, $final['status']);
        $this->assertSame('Carlos Perez', $final['side1']['display_name']);
        $this->assertSame('Juan Gomez', $final['side2']['display_name']);
        $this->assertNull($final['side1_placeholder']);
        $this->assertNull($final['side2_placeholder']);
        $this->assertNull($payload['champion']);
    }

    public function test_play_in_uses_persisted_first_round_label(): void
    {
        $payload = $this->build(size: 16, snapshots: [
            $this->pendingMatch(
                round: 1,
                match: 1,
                side1Id: 1,
                side2Id: 2,
                label: BracketSupport::PLAY_IN_ROUND_LABEL,
            ),
        ]);

        $this->assertSame(BracketSupport::PLAY_IN_ROUND_LABEL, $payload['rounds'][0]['label']);
        $this->assertSame('Cuartos de final', $payload['rounds'][1]['label']);
        $this->assertSame('Semifinal', $payload['rounds'][2]['label']);
        $this->assertSame('Final', $payload['rounds'][3]['label']);
    }

    public function test_playoff_third_place_placeholder_when_match_missing(): void
    {
        $payload = $this->build(
            size: 4,
            mode: ThirdPlaceMode::Playoff,
            snapshots: [
                $this->pendingMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, label: 'Semifinal'),
                $this->pendingMatch(round: 1, match: 2, side1Id: 3, side2Id: 4, label: 'Semifinal'),
            ],
        );

        $this->assertSame(ThirdPlaceMode::Playoff->value, $payload['third_place']['mode']);
        $this->assertFalse($payload['third_place']['exists_in_database']);
        $this->assertSame('Perdedor semifinal 1', $payload['third_place']['side1_placeholder']);
        $this->assertSame('Perdedor semifinal 2', $payload['third_place']['side2_placeholder']);
    }

    public function test_playoff_third_place_hydrates_semifinal_losers(): void
    {
        $payload = $this->build(
            size: 4,
            mode: ThirdPlaceMode::Playoff,
            snapshots: [
                $this->finishedMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, winnerId: 1, winnerName: 'Carlos Perez', side2Name: 'Pedro Ruiz', label: 'Semifinal'),
                $this->finishedMatch(round: 1, match: 2, side1Id: 3, side2Id: 4, winnerId: 3, winnerName: 'Juan Gomez', side2Name: 'Luis Lopez', label: 'Semifinal'),
            ],
        );

        $this->assertSame('Pedro Ruiz', $payload['third_place']['side1']['display_name']);
        $this->assertSame('Luis Lopez', $payload['third_place']['side2']['display_name']);
        $this->assertNull($payload['third_place']['side1_placeholder']);
        $this->assertNull($payload['third_place']['side2_placeholder']);
    }

    public function test_playoff_third_place_uses_persisted_match(): void
    {
        $payload = $this->build(
            size: 4,
            mode: ThirdPlaceMode::Playoff,
            snapshots: [
                $this->finishedMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, winnerId: 1, winnerName: 'Carlos Perez', side2Name: 'Pedro Ruiz', label: 'Semifinal'),
                $this->finishedMatch(round: 1, match: 2, side1Id: 3, side2Id: 4, winnerId: 3, winnerName: 'Juan Gomez', side2Name: 'Luis Lopez', label: 'Semifinal'),
                $this->thirdPlaceMatch(side1Id: 2, side2Id: 4, side1Name: 'Pedro Ruiz', side2Name: 'Luis Lopez'),
            ],
        );

        $this->assertTrue($payload['third_place']['exists_in_database']);
        $this->assertSame('Pedro Ruiz', $payload['third_place']['side1']['display_name']);
        $this->assertSame('Luis Lopez', $payload['third_place']['side2']['display_name']);
    }

    public function test_shared_third_place_is_informational(): void
    {
        $payload = $this->buildEmpty(size: 4, mode: ThirdPlaceMode::Shared);

        $this->assertSame([
            'mode' => ThirdPlaceMode::Shared->value,
            'label' => 'Tercer puesto compartido',
        ], $payload['third_place']);
    }

    public function test_none_third_place_is_null(): void
    {
        $payload = $this->buildEmpty(size: 4, mode: ThirdPlaceMode::None);

        $this->assertNull($payload['third_place']);
    }

    public function test_bye_in_semifinal_hides_playoff_third_place(): void
    {
        $payload = $this->build(
            size: 4,
            mode: ThirdPlaceMode::Playoff,
            snapshots: [
                $this->byeMatch(round: 1, match: 1, entryId: 1, name: 'Carlos Perez', label: 'Semifinal'),
                $this->pendingMatch(round: 1, match: 2, side1Id: 2, side2Id: 3, label: 'Semifinal'),
            ],
        );

        $this->assertNull($payload['third_place']);
    }

    public function test_finished_final_returns_champion(): void
    {
        $payload = $this->build(size: 2, snapshots: [
            $this->finishedMatch(round: 1, match: 1, side1Id: 1, side2Id: 2, winnerId: 1, winnerName: 'Carlos Perez', label: 'Final'),
        ]);

        $this->assertSame('Carlos Perez', $payload['champion']['display_name']);
        $this->assertSame(1, $payload['champion']['competition_entry_id']);
        $this->assertSame('Carlos Perez', $payload['rounds'][0]['matches'][0]['winner']['display_name']);
    }

    public function test_output_is_deterministic(): void
    {
        $snapshots = [
            $this->pendingMatch(round: 1, match: 2, side1Id: 3, side2Id: 4),
            $this->pendingMatch(round: 1, match: 1, side1Id: 1, side2Id: 2),
        ];

        $first = $this->build(size: 4, snapshots: $snapshots);
        $second = $this->build(size: 4, snapshots: array_reverse($snapshots));

        $this->assertSame($first, $second);
        $this->assertSame(1, $first['rounds'][0]['matches'][0]['match_number']);
        $this->assertSame(2, $first['rounds'][0]['matches'][1]['match_number']);
    }

    public function test_normalized_snapshots_produce_the_same_structure_regardless_of_entity(): void
    {
        $snapshots = [
            $this->pendingMatch(round: 1, match: 1, side1Id: 10, side2Id: 11, side1Name: 'Equipo Azul', side2Name: 'Equipo Rojo'),
        ];

        $payload = $this->build(size: 2, snapshots: $snapshots);
        $final = $payload['rounds'][0]['matches'][0];

        $this->assertSame('Equipo Azul', $final['side1']['display_name']);
        $this->assertSame('Equipo Rojo', $final['side2']['display_name']);
        $this->assertArrayNotHasKey('sets', $final);
        $this->assertArrayNotHasKey('score', $final);
        $this->assertArrayNotHasKey('player1', $final);
        $this->assertSame('main', $final['purpose']);
    }

    /**
     * @param  list<PrintBracketMatchSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    private function build(
        int $size,
        array $snapshots = [],
        ThirdPlaceMode $mode = ThirdPlaceMode::None,
    ): array {
        $competition = new Competition([
            'id' => 10,
            'name' => 'Singles Test',
            'type' => CompetitionType::Singles,
            'third_place_mode' => $mode,
        ]);
        $bracket = new Bracket([
            'id' => 20,
            'name' => 'Llave',
            'competition_id' => 10,
            'bracket_size' => $size,
            'byes_count' => 0,
        ]);

        return $this->builder->build(
            $bracket,
            $competition,
            $snapshots,
            ['id' => 1, 'name' => 'Torneo Test'],
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmpty(int $size, ThirdPlaceMode $mode = ThirdPlaceMode::None): array
    {
        return $this->build(size: $size, mode: $mode);
    }

    private function pendingMatch(
        int $round,
        int $match,
        int $side1Id,
        int $side2Id,
        string $side1Name = 'Jugador A',
        string $side2Name = 'Jugador B',
        string $label = 'Cuartos de final',
    ): PrintBracketMatchSnapshot {
        return new PrintBracketMatchSnapshot(
            bracketRound: $round,
            bracketMatch: $match,
            purpose: BracketGamePurpose::Main->value,
            isBye: false,
            status: GameStatus::Pending->value,
            roundLabel: $label,
            side1: $this->side($side1Id, $side1Name),
            side2: $this->side($side2Id, $side2Name),
            winner: null,
            winnerEntryId: null,
            entry1Id: $side1Id,
            entry2Id: $side2Id,
        );
    }

    private function finishedMatch(
        int $round,
        int $match,
        int $side1Id,
        int $side2Id,
        int $winnerId,
        string $winnerName,
        string $side1Name = 'Jugador A',
        string $side2Name = 'Jugador B',
        string $label = 'Cuartos de final',
    ): PrintBracketMatchSnapshot {
        $side1 = $this->side($side1Id, $side1Id === $winnerId ? $winnerName : $side1Name);
        $side2 = $this->side($side2Id, $side2Id === $winnerId ? $winnerName : $side2Name);

        return new PrintBracketMatchSnapshot(
            bracketRound: $round,
            bracketMatch: $match,
            purpose: BracketGamePurpose::Main->value,
            isBye: false,
            status: GameStatus::Finished->value,
            roundLabel: $label,
            side1: $side1,
            side2: $side2,
            winner: $this->side($winnerId, $winnerName),
            winnerEntryId: $winnerId,
            entry1Id: $side1Id,
            entry2Id: $side2Id,
        );
    }

    private function byeMatch(
        int $round,
        int $match,
        int $entryId,
        string $name,
        string $label = 'Cuartos de final',
    ): PrintBracketMatchSnapshot {
        $side = $this->side($entryId, $name);

        return new PrintBracketMatchSnapshot(
            bracketRound: $round,
            bracketMatch: $match,
            purpose: BracketGamePurpose::Main->value,
            isBye: true,
            status: GameStatus::Finished->value,
            roundLabel: $label,
            side1: $side,
            side2: null,
            winner: $side,
            winnerEntryId: $entryId,
            entry1Id: $entryId,
            entry2Id: null,
        );
    }

    private function thirdPlaceMatch(
        int $side1Id,
        int $side2Id,
        string $side1Name,
        string $side2Name,
    ): PrintBracketMatchSnapshot {
        return new PrintBracketMatchSnapshot(
            bracketRound: null,
            bracketMatch: 1,
            purpose: BracketGamePurpose::ThirdPlace->value,
            isBye: false,
            status: GameStatus::Pending->value,
            roundLabel: 'Tercer puesto',
            side1: $this->side($side1Id, $side1Name),
            side2: $this->side($side2Id, $side2Name),
            winner: null,
            winnerEntryId: null,
            entry1Id: $side1Id,
            entry2Id: $side2Id,
        );
    }

    /**
     * @return array{competition_entry_id: int, display_name: string}
     */
    private function side(int $id, string $name): array
    {
        return [
            'competition_entry_id' => $id,
            'display_name' => $name,
        ];
    }
}
