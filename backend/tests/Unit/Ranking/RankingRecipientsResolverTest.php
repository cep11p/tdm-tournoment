<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionFormat;
use App\Models\CompetitionEntryMember;
use App\Models\CompetitionFinalStanding;
use App\Models\Player;
use App\Support\Ranking\RankingRecipientsResolver;
use App\Support\Ranking\RankingTypeGuard;
use Tests\TestCase;

class RankingRecipientsResolverTest extends TestCase
{
    public function test_singles_returns_exactly_one_player(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $standing = $competition->fresh()->finalStandings()->where('position', 1)->firstOrFail();
        $recipients = app(RankingRecipientsResolver::class)->resolve($standing);

        $this->assertCount(1, $recipients);
        $this->assertSame($players[0]->id, $recipients[0]->id);
    }

    public function test_doubles_returns_two_players_in_member_order(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPair($competition, $players[0], $players[1]);
        $context->registerPair($competition, $players[2], $players[3]);
        $context->createBracket($competition)->assertCreated();

        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $winnerEntryId = (int) $final->entry1_id;
        $context->finishGameByEntryViaApi($final, $winnerEntryId)->assertOk();

        $standing = $competition->fresh()->finalStandings()->where('position', 1)->firstOrFail();
        $recipients = app(RankingRecipientsResolver::class)->resolve($standing);

        $this->assertCount(2, $recipients);

        $orderedIds = CompetitionEntryMember::query()
            ->where('competition_entry_id', $standing->competition_entry_id)
            ->orderBy('member_order')
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame($orderedIds, array_map(fn (Player $player): int => (int) $player->id, $recipients));
    }

    public function test_team_throws_explicit_exception(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createTeamCompetition(2, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 2);
        $context->createBracket($competition)->assertCreated();

        $standing = CompetitionFinalStanding::query()->create([
            'competition_id' => $competition->id,
            'competition_entry_id' => $entries[0]->id,
            'position' => 1,
            'position_range_end' => 1,
            'source' => CompetitionFinalStandingSource::Final,
            'display_name_snapshot' => $entries[0]->display_name,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RankingTypeGuard::TEAM_UNSUPPORTED_MESSAGE);

        app(RankingRecipientsResolver::class)->resolve($standing);
    }

    public function test_invalid_singles_cardinality_throws(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, [$players[0], $players[1]]);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $standing = $competition->fresh()->finalStandings()->where('position', 1)->firstOrFail();

        CompetitionEntryMember::query()->create([
            'competition_entry_id' => $standing->competition_entry_id,
            'competition_id' => $competition->id,
            'player_id' => $players[2]->id,
            'member_order' => 2,
        ]);

        $standing->unsetRelation('entry');

        $this->expectException(\LogicException::class);

        app(RankingRecipientsResolver::class)->resolve($standing->fresh());
    }
}
