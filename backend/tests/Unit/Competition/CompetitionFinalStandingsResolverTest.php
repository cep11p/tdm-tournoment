<?php

namespace Tests\Unit\Competition;

use App\Enums\ThirdPlaceMode;
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

    /**
     * @param  list<\App\Data\Competition\CompetitionFinalStandingData>  $dtos
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
