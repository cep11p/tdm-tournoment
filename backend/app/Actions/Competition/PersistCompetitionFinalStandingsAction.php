<?php

namespace App\Actions\Competition;

use App\Data\Competition\CompetitionFinalStandingData;
use App\Models\Competition;
use App\Models\CompetitionFinalStanding;
use App\Support\Competition\CompetitionFinalStandingsResolver;
use App\Support\Competition\CompetitionStatusResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistCompetitionFinalStandingsAction
{
    public const INCOMPLETE_MESSAGE = 'No se puede consolidar la clasificación final porque la competencia no está finalizada.';

    public function __construct(
        private readonly CompetitionFinalStandingsResolver $resolver,
    ) {}

    /**
     * @return list<CompetitionFinalStanding>
     */
    public function __invoke(Competition $competition): array
    {
        return DB::transaction(function () use ($competition): array {
            $competition = Competition::query()->lockForUpdate()->findOrFail($competition->id);

            $this->assertCompleted($competition);

            $dtos = $this->resolver->resolve($competition);

            CompetitionFinalStanding::query()
                ->where('competition_id', $competition->id)
                ->delete();

            $rows = [];

            foreach ($dtos as $dto) {
                $rows[] = $this->insert($competition, $dto);
            }

            return $rows;
        });
    }

    public function persistIfCompleted(Competition $competition): void
    {
        $status = CompetitionStatusResolver::resolve($competition->fresh());

        if ($status['code'] !== 'completed') {
            return;
        }

        $this($competition);
    }

    private function assertCompleted(Competition $competition): void
    {
        $status = CompetitionStatusResolver::resolve($competition);

        if ($status['code'] !== 'completed') {
            throw ValidationException::withMessages([
                'competition' => [self::INCOMPLETE_MESSAGE],
            ]);
        }
    }

    private function insert(Competition $competition, CompetitionFinalStandingData $dto): CompetitionFinalStanding
    {
        return CompetitionFinalStanding::query()->create([
            'competition_id' => $competition->id,
            'competition_entry_id' => $dto->competitionEntryId,
            'position' => $dto->position,
            'position_range_end' => $dto->positionRangeEnd,
            'source' => $dto->source,
            'display_name_snapshot' => $dto->displayNameSnapshot,
        ]);
    }
}
