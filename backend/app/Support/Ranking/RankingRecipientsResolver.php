<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\CompetitionEntryMember;
use App\Models\CompetitionFinalStanding;
use App\Models\Player;
use Illuminate\Support\Collection;

final class RankingRecipientsResolver
{
    public const TEAM_UNSUPPORTED_MESSAGE = RankingTypeGuard::TEAM_UNSUPPORTED_MESSAGE;

    /**
     * @return list<Player>
     */
    public function resolve(CompetitionFinalStanding $standing): array
    {
        $standing->loadMissing(['competition', 'entry.members.player']);

        $competition = $standing->competition;
        $type = $competition?->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::tryFrom((string) ($competition?->type ?? ''));

        if ($type === null) {
            throw new \LogicException('La competencia del standing de ranking no tiene un tipo válido.');
        }

        if ($type->isTeam()) {
            throw new \LogicException(self::TEAM_UNSUPPORTED_MESSAGE);
        }

        $expected = $type->isDoubles() ? 2 : 1;
        $members = $this->orderedMembers($standing);

        if ($members->count() !== $expected) {
            throw new \LogicException(sprintf(
                'La inscripción del ranking debe tener exactamente %d jugador(es) para competencias %s.',
                $expected,
                $type->value,
            ));
        }

        $players = [];

        foreach ($members as $member) {
            $player = $member->player;

            if (! $player instanceof Player) {
                throw new \LogicException('La inscripción del ranking referencia un jugador inexistente.');
            }

            $players[] = $player;
        }

        return $players;
    }

    /**
     * @return Collection<int, CompetitionEntryMember>
     */
    private function orderedMembers(CompetitionFinalStanding $standing): Collection
    {
        $entry = $standing->entry;

        if ($entry === null) {
            throw new \LogicException('El standing de ranking no tiene una inscripción asociada.');
        }

        return $entry->members
            ->sortBy([
                ['member_order', 'asc'],
                ['player_id', 'asc'],
            ])
            ->values();
    }
}
