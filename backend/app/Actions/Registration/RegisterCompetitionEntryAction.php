<?php

namespace App\Actions\Registration;

use App\Actions\CompetitionEntry\PersistCompetitionEntryAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class RegisterCompetitionEntryAction
{
    public function __construct(
        private readonly PersistCompetitionEntryAction $persistCompetitionEntry,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): CompetitionEntry
    {
        return DB::transaction(function () use ($payload): CompetitionEntry {
            $entry = ($this->persistCompetitionEntry)($payload);

            $competition = Competition::query()->findOrFail($entry->competition_id);

            $type = $competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type);

            if ($type->isMultiMember()) {
                $this->auditMultiMemberRegistration($competition, $entry, $payload, $type);

                return $entry;
            }

            $playerId = (int) $payload['player_id'];
            $player = Player::query()->findOrFail($playerId);

            $context = AuditContextBuilder::fromRegistrationContext(
                $competition,
                $player,
                $entry,
            );

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::REGISTRATION_CREATED,
                logName: 'registrations',
                subject: $competition,
                context: $context,
                new: [
                    'competition_id' => $entry->competition_id,
                    'player_id' => $playerId,
                    'competition_entry_id' => $entry->id,
                ],
                summary: [
                    'registration_id' => $entry->id,
                    'competition_entry_id' => $entry->id,
                    'player_id' => $player->id,
                    'player_name' => $context['player_name'],
                ],
            ));

            return $entry;
        });
    }

    /**
     * @param  array{player_ids: list<int>, name?: string}  $payload
     */
    private function auditMultiMemberRegistration(
        Competition $competition,
        CompetitionEntry $entry,
        array $payload,
        CompetitionType $type,
    ): void {
        $entry->loadMissing(['members.player']);

        $playerIds = array_map('intval', $payload['player_ids']);

        $players = Player::query()
            ->whereIn('id', $playerIds)
            ->get()
            ->sortBy(fn (Player $player): int => (int) array_search($player->id, $playerIds, true))
            ->values();

        $context = $type->isTeam()
            ? AuditContextBuilder::fromTeamRegistrationContext($competition, $entry, $players)
            : AuditContextBuilder::fromDoublesRegistrationContext($competition, $entry, $players);

        $summary = [
            'registration_id' => $entry->id,
            'competition_entry_id' => $entry->id,
            'member_ids' => $context['member_ids'],
            'member_names' => $context['member_names'],
            'display_name' => $context['display_name'],
        ];

        if ($type->isTeam()) {
            $summary['team_name'] = $context['team_name'] ?? $context['display_name'];
        }

        $this->auditLogger->log(new AuditEntry(
            action: AuditAction::REGISTRATION_CREATED,
            logName: 'registrations',
            subject: $competition,
            context: $context,
            new: [
                'competition_id' => $entry->competition_id,
                'competition_entry_id' => $entry->id,
                'member_ids' => $context['member_ids'],
            ],
            summary: $summary,
        ));
    }
}
