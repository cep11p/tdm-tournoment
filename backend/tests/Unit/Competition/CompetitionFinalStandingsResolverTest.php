<?php

namespace Tests\Unit\Competition;

use App\Data\Competition\CompetitionFinalStandingData;
use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\ThirdPlaceMode;
use App\Models\GroupEntry;
use App\Support\Competition\CompetitionFinalStandingsResolver;
use App\Support\Competition\CompetitionResultResolver;
use Tests\TestCase;

class CompetitionFinalStandingsResolverTest extends TestCase
{
    public function test_none_mode_persists_shared_third_while_podium_stays_empty(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $dtos = app(CompetitionFinalStandingsResolver::class)->resolve($competition->fresh());
        $thirds = array_values(array_filter(
            $dtos,
            fn ($dto): bool => $dto->position === 3,
        ));

        $this->assertCount(4, $dtos);
        $this->assertCount(2, $thirds);
        $this->assertSame(4, $thirds[0]->positionRangeEnd);
        $this->assertSame(4, $thirds[1]->positionRangeEnd);

        $result = CompetitionResultResolver::resolve($competition->fresh());
        $this->assertNotNull($result);
        $this->assertSame(ThirdPlaceMode::None->value, $result['third_place_mode']);
        $this->assertSame([], $result['third_place']);
        $this->assertNull($result['fourth_place']);
    }

    public function test_resolve_is_deterministic(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $resolver = app(CompetitionFinalStandingsResolver::class);
        $first = $resolver->resolve($competition->fresh());
        $second = $resolver->resolve($competition->fresh());

        $this->assertSame($this->signature($first), $this->signature($second));
    }

    public function test_doubles_uses_entry_ids_not_players(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);
        $context->createBracket($competition)->assertCreated();
        $context->completeDoublesCompetitionThroughFinal($competition);

        $dtos = app(CompetitionFinalStandingsResolver::class)->resolve($competition->fresh());
        $entryIds = $competition->entries()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $resolvedIds = array_map(fn ($dto): int => $dto->competitionEntryId, $dtos);
        sort($resolvedIds);

        $this->assertCount(2, $dtos);
        $this->assertSame($entryIds, $resolvedIds);
        $this->assertSame(1, $dtos[0]->position);
        $this->assertSame(2, $dtos[1]->position);
    }

    public function test_knockout_direct_places_entries_outside_the_draw_in_a_shared_not_in_draw_range(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, array_slice($players, 0, 6));
        $context->createBracket($competition)->assertCreated();

        $activeOut = $context->attachSinglesEntry($competition, $players[6]);
        $withdrawnOut = $context->attachSinglesEntry(
            $competition,
            $players[7],
            CompetitionEntryStatus::Withdrawn,
        );

        $this->assertFalse(
            $competition->games()
                ->whereNotNull('bracket_id')
                ->where(function ($query) use ($activeOut, $withdrawnOut): void {
                    $query->whereIn('entry1_id', [$activeOut->id, $withdrawnOut->id])
                        ->orWhereIn('entry2_id', [$activeOut->id, $withdrawnOut->id]);
                })
                ->exists(),
        );

        $context->completeCompetitionThroughFinal($competition);

        $dtos = app(CompetitionFinalStandingsResolver::class)->resolve($competition->fresh());
        $byEntry = $this->byEntry($dtos);

        $this->assertCount(8, $dtos);
        $this->assertSame(
            $competition->entries()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            collect($dtos)->pluck('competitionEntryId')->sort()->values()->all(),
        );

        $sporting = array_values(array_filter(
            $dtos,
            fn ($dto): bool => $dto->source !== CompetitionFinalStandingSource::NotInDraw,
        ));
        $this->assertCount(6, $sporting);
        $this->assertSame(1, $sporting[0]->position);

        foreach ([$activeOut->id, $withdrawnOut->id] as $entryId) {
            $placement = $byEntry[(int) $entryId];
            $this->assertSame(CompetitionFinalStandingSource::NotInDraw, $placement->source);
            $this->assertSame(7, $placement->position);
            $this->assertSame(8, $placement->positionRangeEnd);
            $this->assertNotSame('', $placement->displayNameSnapshot);
        }

        $this->assertSame(CompetitionEntryStatus::Active, $activeOut->fresh()->status);
        $this->assertSame(CompetitionEntryStatus::Withdrawn, $withdrawnOut->fresh()->status);
    }

    public function test_groups_knockout_places_entries_never_assigned_to_a_group_as_not_in_draw(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, array_slice($players, 0, 6));
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(6, GroupEntry::query()->where('competition_id', $competition->id)->count());

        $activeOut = $context->registerPlayer($competition, $players[6]);
        $withdrawnOut = $context->registerPlayer($competition, $players[7]);
        $withdrawnOut->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $this->assertFalse(
            GroupEntry::query()
                ->where('competition_id', $competition->id)
                ->whereIn('competition_entry_id', [$activeOut->id, $withdrawnOut->id])
                ->exists(),
        );

        $context->finishPendingGroupGames($competition);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $dtos = app(CompetitionFinalStandingsResolver::class)->resolve($competition->fresh());

        $this->assertCount(8, $dtos);

        $notInDraw = array_values(array_filter(
            $dtos,
            fn ($dto): bool => $dto->source === CompetitionFinalStandingSource::NotInDraw,
        ));
        $this->assertCount(2, $notInDraw);
        $this->assertSame(
            [(int) $activeOut->id, (int) $withdrawnOut->id],
            collect($notInDraw)->pluck('competitionEntryId')->sort()->values()->all(),
        );
        $this->assertSame(7, $notInDraw[0]->position);
        $this->assertSame(8, $notInDraw[0]->positionRangeEnd);
        $this->assertSame(7, $notInDraw[1]->position);
        $this->assertSame(8, $notInDraw[1]->positionRangeEnd);

        $groupStageCount = count(array_filter(
            $dtos,
            fn ($dto): bool => $dto->source === CompetitionFinalStandingSource::GroupStage,
        ));
        $this->assertSame(2, $groupStageCount);

        $this->assertSame(CompetitionEntryStatus::Active, $activeOut->fresh()->status);
        $this->assertSame(CompetitionEntryStatus::Withdrawn, $withdrawnOut->fresh()->status);
    }

    public function test_withdrawn_before_group_generation_appears_as_not_in_draw(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $withdrawn = $competition->entries()->orderByDesc('id')->take(2)->get();
        foreach ($withdrawn as $entry) {
            $entry->update(['status' => CompetitionEntryStatus::Withdrawn]);
        }

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();
        $this->assertSame(6, GroupEntry::query()->where('competition_id', $competition->id)->count());

        $context->finishPendingGroupGames($competition);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $dtos = app(CompetitionFinalStandingsResolver::class)->resolve($competition->fresh());
        $notInDrawIds = collect($dtos)
            ->filter(fn ($dto): bool => $dto->source === CompetitionFinalStandingSource::NotInDraw)
            ->pluck('competitionEntryId')
            ->sort()
            ->values()
            ->all();

        $this->assertCount(8, $dtos);
        $this->assertSame(
            $withdrawn->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            $notInDrawIds,
        );

        foreach ($withdrawn as $entry) {
            $this->assertSame(CompetitionEntryStatus::Withdrawn, $entry->fresh()->status);
        }
    }

    /**
     * @param  list<CompetitionFinalStandingData>  $dtos
     * @return array<int, CompetitionFinalStandingData>
     */
    private function byEntry(array $dtos): array
    {
        $map = [];

        foreach ($dtos as $dto) {
            $map[$dto->competitionEntryId] = $dto;
        }

        return $map;
    }

    /**
     * @param  list<CompetitionFinalStandingData>  $dtos
     * @return list<array{int, int, int, string, string}>
     */
    private function signature(array $dtos): array
    {
        return array_map(
            fn ($dto): array => [
                $dto->competitionEntryId,
                $dto->position,
                $dto->positionRangeEnd,
                $dto->source->value,
                $dto->displayNameSnapshot,
            ],
            $dtos,
        );
    }
}
