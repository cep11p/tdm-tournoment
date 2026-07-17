<?php

namespace App\Actions\Registration;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Player;
use App\Models\Registration;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BulkRegisterPlayersToCompetitionAction
{
    private const ID_LIST_LIMIT = 20;

    private const NAME_SAMPLE_LIMIT = 5;

    public function __construct(
        private readonly PersistRegistrationAction $persistRegistration,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<int, int>  $playerIds
     * @return array{created: int, skipped: int, total: int}
     */
    public function __invoke(int $competitionId, array $playerIds): array
    {
        $uniqueIds = array_values(array_unique($playerIds));

        return DB::transaction(function () use ($competitionId, $uniqueIds): array {
            $competition = Competition::query()->findOrFail($competitionId);
            $competition->loadMissing('tournament');

            $existingPlayerIds = Registration::query()
                ->where('competition_id', $competitionId)
                ->whereIn('player_id', $uniqueIds)
                ->pluck('player_id')
                ->all();

            $existingSet = array_fill_keys($existingPlayerIds, true);

            $created = 0;
            $skipped = 0;
            $createdPlayerIds = [];
            $skippedPlayerIds = [];

            foreach ($uniqueIds as $playerId) {
                if (isset($existingSet[$playerId])) {
                    $skipped++;
                    $skippedPlayerIds[] = $playerId;

                    continue;
                }

                try {
                    ($this->persistRegistration)([
                        'competition_id' => $competitionId,
                        'player_id' => $playerId,
                    ]);

                    $existingSet[$playerId] = true;
                    $created++;
                    $createdPlayerIds[] = $playerId;
                } catch (ValidationException $exception) {
                    if (isset($exception->errors()['player_id'])) {
                        $skipped++;
                        $skippedPlayerIds[] = $playerId;

                        continue;
                    }

                    throw $exception;
                }
            }

            $total = count($uniqueIds);

            $summary = [
                'requested_count' => $total,
                'created_count' => $created,
                'skipped_count' => $skipped,
            ];

            $new = [
                'requested_count' => $total,
                'created_count' => $created,
                'skipped_count' => $skipped,
            ];

            if ($total <= self::ID_LIST_LIMIT) {
                $summary['created_player_ids'] = $createdPlayerIds;
                $summary['skipped_player_ids'] = $skippedPlayerIds;
                $new['created_player_ids'] = $createdPlayerIds;
                $new['skipped_player_ids'] = $skippedPlayerIds;
            } else {
                $summary['sample_created_names'] = $this->samplePlayerNames($createdPlayerIds);
                $summary['sample_skipped_names'] = $this->samplePlayerNames($skippedPlayerIds);
                $new['sample_created_names'] = $summary['sample_created_names'];
                $new['sample_skipped_names'] = $summary['sample_skipped_names'];
            }

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::REGISTRATION_BULK_CREATED,
                logName: 'registrations',
                subject: $competition,
                context: AuditContextBuilder::fromCompetition($competition),
                new: $new,
                summary: $summary,
            ));

            return [
                'created' => $created,
                'skipped' => $skipped,
                'total' => $total,
            ];
        });
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<string>
     */
    private function samplePlayerNames(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $sampleIds = array_slice($playerIds, 0, self::NAME_SAMPLE_LIMIT);

        return Player::query()
            ->whereIn('id', $sampleIds)
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Player $player): string => trim("{$player->first_name} {$player->last_name}"))
            ->values()
            ->all();
    }
}
