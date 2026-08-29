<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateManualGameAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly ResolveSinglesEntryForPlayer $resolveSinglesEntryForPlayer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Game
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);

        [$entry1, $entry2] = $this->resolveEntries($competition, $payload);

        return DB::transaction(function () use ($payload, $entry1, $entry2): Game {
            $game = ($this->createGame)([
                ...$payload,
                'entry1_id' => $entry1->id,
                'entry2_id' => $entry2->id,
            ]);

            $game->load(Game::DISPLAY_RELATIONS);
            $game->loadMissing('competition');

            $context = AuditContextBuilder::fromGame($game);
            $pointsPerSet = $game->competition?->points_per_set;
            $isSingles = $game->competition?->type === CompetitionType::Singles;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_CREATED,
                logName: 'games',
                subject: $game,
                context: $context,
                new: array_merge([
                    'entry1_id' => $entry1->id,
                    'entry1_display_name' => CompetitionEntryDisplayName::for($entry1),
                    'entry2_id' => $entry2->id,
                    'entry2_display_name' => CompetitionEntryDisplayName::for($entry2),
                    'round' => $game->round,
                    'group_id' => $game->group_id,
                    'bracket_id' => $game->bracket_id,
                    'best_of' => $game->best_of,
                    'sets_to_win' => $game->sets_to_win,
                    'points_per_set' => $pointsPerSet,
                    'status' => $game->status instanceof GameStatus ? $game->status->value : (string) $game->status,
                    'is_bye' => (bool) $game->is_bye,
                ], $isSingles ? [
                    'player1_id' => $game->singlesPlayer1Id(),
                    'player2_id' => $game->singlesPlayer2Id(),
                ] : []),
                summary: [
                    'game_id' => $game->id,
                    'entry1_display_name' => $context['entry1_display_name'] ?? CompetitionEntryDisplayName::for($entry1),
                    'entry2_display_name' => $context['entry2_display_name'] ?? CompetitionEntryDisplayName::for($entry2),
                    'player1_name' => $context['player1_name'] ?? null,
                    'player2_name' => $context['player2_name'] ?? null,
                    'round' => $game->round,
                ],
            ));

            return $game;
        });
    }

    /**
     * @return array{0: CompetitionEntry, 1: CompetitionEntry}
     */
    private function resolveEntries(Competition $competition, array $payload): array
    {
        if ($competition->type === CompetitionType::Doubles) {
            return [
                $this->resolveEntryById($competition, (int) $payload['entry1_id'], 'entry1_id'),
                $this->resolveEntryById($competition, (int) $payload['entry2_id'], 'entry2_id'),
            ];
        }

        if (isset($payload['entry1_id'], $payload['entry2_id'])) {
            return [
                $this->resolveEntryById($competition, (int) $payload['entry1_id'], 'entry1_id'),
                $this->resolveEntryById($competition, (int) $payload['entry2_id'], 'entry2_id'),
            ];
        }

        $entry1 = ($this->resolveSinglesEntryForPlayer)($competition, (int) $payload['player1_id']);
        $entry2 = ($this->resolveSinglesEntryForPlayer)($competition, (int) $payload['player2_id']);

        return [$entry1, $entry2];
    }

    private function resolveEntryById(Competition $competition, int $entryId, string $field): CompetitionEntry
    {
        $entry = CompetitionEntry::query()
            ->where('id', $entryId)
            ->where('competition_id', $competition->id)
            ->with('members')
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages([
                $field => ['La participación no pertenece a esta competencia.'],
            ]);
        }

        if ($competition->type === CompetitionType::Doubles && $entry->members->count() !== 2) {
            throw ValidationException::withMessages([
                $field => ['La participación no es una pareja válida para esta competencia.'],
            ]);
        }

        if ($competition->type === CompetitionType::Singles && $entry->members->count() !== 1) {
            throw ValidationException::withMessages([
                $field => ['La participación no es un jugador válido para esta competencia.'],
            ]);
        }

        return $entry;
    }
}
