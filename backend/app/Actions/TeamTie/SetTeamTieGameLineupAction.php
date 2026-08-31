<?php

namespace App\Actions\TeamTie;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Enums\TeamTieGameSide;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntryMember;
use App\Models\Player;
use App\Models\TeamTieGame;
use App\Models\TeamTieGameMember;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\TeamTie\TeamTieGameLineupGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetTeamTieGameLineupAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{entry1_player_ids: list<int>, entry2_player_ids: list<int>}  $payload
     */
    public function __invoke(TeamTieGame $teamTieGame, array $payload): TeamTieGame
    {
        return DB::transaction(function () use ($teamTieGame, $payload): TeamTieGame {
            $teamTieGame = TeamTieGame::query()
                ->with([
                    'teamTie.competition.tournament',
                    'teamTie.entry1',
                    'teamTie.entry2',
                    'game',
                    'members.competitionEntryMember.player',
                ])
                ->lockForUpdate()
                ->findOrFail($teamTieGame->id);

            $teamTie = $teamTieGame->teamTie;
            $competition = $teamTie?->competition;

            if ($competition === null) {
                throw ValidationException::withMessages([
                    'team_tie_game' => ['El partido interno no está asociado a una competencia válida.'],
                ]);
            }

            TournamentLifecycleGuard::ensureMutableForCompetition($competition);

            $type = $competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type);

            if ($type !== CompetitionType::Team) {
                throw ValidationException::withMessages([
                    'competition' => ['El lineup solo aplica a competencias por equipos.'],
                ]);
            }

            TeamTieGameLineupGuard::assertEditable($teamTieGame);

            $requiredPerSide = $teamTieGame->modality === TeamTieModality::Doubles ? 2 : 1;

            $entry1PlayerIds = array_values(array_map('intval', $payload['entry1_player_ids']));
            $entry2PlayerIds = array_values(array_map('intval', $payload['entry2_player_ids']));

            $this->assertCardinality($entry1PlayerIds, $entry2PlayerIds, $requiredPerSide);
            $this->assertDistinctWithinSide($entry1PlayerIds, 'entry1_player_ids');
            $this->assertDistinctWithinSide($entry2PlayerIds, 'entry2_player_ids');

            $entry1Members = $this->resolveMembersForSide(
                $teamTie,
                (int) $teamTie->entry1_id,
                $entry1PlayerIds,
                TeamTieGameSide::Entry1,
            );
            $entry2Members = $this->resolveMembersForSide(
                $teamTie,
                (int) $teamTie->entry2_id,
                $entry2PlayerIds,
                TeamTieGameSide::Entry2,
            );

            $oldLineup = $this->lineupSnapshot($teamTieGame);

            $teamTieGame->members()->delete();

            foreach ($entry1Members as $index => $member) {
                TeamTieGameMember::query()->create([
                    'team_tie_game_id' => $teamTieGame->id,
                    'competition_entry_member_id' => $member->id,
                    'side' => TeamTieGameSide::Entry1,
                    'player_order' => $index + 1,
                ]);
            }

            foreach ($entry2Members as $index => $member) {
                TeamTieGameMember::query()->create([
                    'team_tie_game_id' => $teamTieGame->id,
                    'competition_entry_member_id' => $member->id,
                    'side' => TeamTieGameSide::Entry2,
                    'player_order' => $index + 1,
                ]);
            }

            $teamTieGame->load(TeamTieGame::DISPLAY_RELATIONS);

            $newLineup = $this->lineupSnapshot($teamTieGame);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TEAM_TIE_GAME_LINEUP_UPDATED,
                logName: 'team_ties',
                subject: $teamTieGame,
                context: [
                    'team_tie_id' => $teamTie->id,
                    'team_tie_game_id' => $teamTieGame->id,
                    'slot_order' => $teamTieGame->slot_order,
                    'modality' => $teamTieGame->modality->value,
                    'entry1_display_name' => CompetitionEntryDisplayName::for($teamTie->entry1),
                    'entry2_display_name' => CompetitionEntryDisplayName::for($teamTie->entry2),
                ],
                old: $oldLineup,
                new: $newLineup,
                summary: [
                    'team_tie_id' => $teamTie->id,
                    'team_tie_game_id' => $teamTieGame->id,
                    'slot_order' => $teamTieGame->slot_order,
                    'modality' => $teamTieGame->modality->value,
                    'entry1_display_name' => CompetitionEntryDisplayName::for($teamTie->entry1),
                    'entry2_display_name' => CompetitionEntryDisplayName::for($teamTie->entry2),
                    'entry1_player_ids' => $newLineup['entry1_player_ids'],
                    'entry1_player_names' => $newLineup['entry1_player_names'],
                    'entry2_player_ids' => $newLineup['entry2_player_ids'],
                    'entry2_player_names' => $newLineup['entry2_player_names'],
                ],
            ));

            return $teamTieGame;
        });
    }

    /**
     * @param  list<int>  $entry1PlayerIds
     * @param  list<int>  $entry2PlayerIds
     */
    private function assertCardinality(array $entry1PlayerIds, array $entry2PlayerIds, int $requiredPerSide): void
    {
        if (count($entry1PlayerIds) !== $requiredPerSide) {
            throw ValidationException::withMessages([
                'entry1_player_ids' => [
                    sprintf('Se requieren exactamente %d jugador(es) para el lado 1.', $requiredPerSide),
                ],
            ]);
        }

        if (count($entry2PlayerIds) !== $requiredPerSide) {
            throw ValidationException::withMessages([
                'entry2_player_ids' => [
                    sprintf('Se requieren exactamente %d jugador(es) para el lado 2.', $requiredPerSide),
                ],
            ]);
        }
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function assertDistinctWithinSide(array $playerIds, string $field): void
    {
        if (count($playerIds) !== count(array_unique($playerIds))) {
            throw ValidationException::withMessages([
                $field => ['No se puede repetir el mismo jugador en el mismo lado.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<CompetitionEntryMember>
     */
    private function resolveMembersForSide(
        \App\Models\TeamTie $teamTie,
        int $entryId,
        array $playerIds,
        TeamTieGameSide $side,
    ): array {
        $entry = $side === TeamTieGameSide::Entry1 ? $teamTie->entry1 : $teamTie->entry2;

        if ($entry === null || (int) $entry->id !== $entryId) {
            throw ValidationException::withMessages([
                'team_tie' => ['El enfrentamiento no tiene un equipo válido para este lado.'],
            ]);
        }

        if ($entry->status !== CompetitionEntryStatus::Active) {
            throw ValidationException::withMessages([
                $side === TeamTieGameSide::Entry1 ? 'entry1_player_ids' : 'entry2_player_ids' => [
                    'El equipo no está activo en la competencia.',
                ],
            ]);
        }

        $members = [];

        foreach ($playerIds as $playerId) {
            $player = Player::query()->find($playerId);

            if ($player === null) {
                throw ValidationException::withMessages([
                    $side === TeamTieGameSide::Entry1 ? 'entry1_player_ids' : 'entry2_player_ids' => [
                        sprintf('El jugador %d no existe.', $playerId),
                    ],
                ]);
            }

            if (! $player->active) {
                throw ValidationException::withMessages([
                    $side === TeamTieGameSide::Entry1 ? 'entry1_player_ids' : 'entry2_player_ids' => [
                        sprintf('El jugador %s no está activo.', $this->playerDisplayName($player)),
                    ],
                ]);
            }

            $member = CompetitionEntryMember::query()
                ->where('competition_entry_id', $entryId)
                ->where('player_id', $playerId)
                ->first();

            if ($member === null) {
                throw ValidationException::withMessages([
                    $side === TeamTieGameSide::Entry1 ? 'entry1_player_ids' : 'entry2_player_ids' => [
                        sprintf('El jugador %s no pertenece al roster del equipo indicado.', $this->playerDisplayName($player)),
                    ],
                ]);
            }

            $members[] = $member;
        }

        return $members;
    }

    /**
     * @return array{
     *     entry1_player_ids: list<int>,
     *     entry1_player_names: list<string>,
     *     entry2_player_ids: list<int>,
     *     entry2_player_names: list<string>,
     * }
     */
    private function lineupSnapshot(TeamTieGame $teamTieGame): array
    {
        $teamTieGame->loadMissing('members.competitionEntryMember.player');

        $entry1 = [];
        $entry2 = [];

        foreach ($teamTieGame->members as $member) {
            $player = $member->competitionEntryMember?->player;
            $payload = [
                'player_id' => $player?->id,
                'name' => $this->playerDisplayName($player),
            ];

            if ($member->side === TeamTieGameSide::Entry1) {
                $entry1[] = $payload;
            } else {
                $entry2[] = $payload;
            }
        }

        usort($entry1, fn (array $left, array $right): int => $left['player_id'] <=> $right['player_id']);
        usort($entry2, fn (array $left, array $right): int => $left['player_id'] <=> $right['player_id']);

        return [
            'entry1_player_ids' => array_values(array_filter(array_column($entry1, 'player_id'))),
            'entry1_player_names' => array_values(array_filter(array_column($entry1, 'name'))),
            'entry2_player_ids' => array_values(array_filter(array_column($entry2, 'player_id'))),
            'entry2_player_names' => array_values(array_filter(array_column($entry2, 'name'))),
        ];
    }

    private function playerDisplayName(?Player $player): string
    {
        if ($player === null) {
            return 'Jugador desconocido';
        }

        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : 'Jugador desconocido';
    }
}
