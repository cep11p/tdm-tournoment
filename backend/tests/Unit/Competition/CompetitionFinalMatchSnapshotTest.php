<?php

namespace Tests\Unit\Competition;

use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\TeamTie;
use App\Support\Competition\CompetitionFinalMatchSnapshot;
use Tests\TestCase;

class CompetitionFinalMatchSnapshotTest extends TestCase
{
    public function test_from_game_maps_geometry_and_loser(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();
        $final->refresh();

        $snapshot = CompetitionFinalMatchSnapshot::fromGame($final);

        $this->assertSame((int) $final->bracket_round, $snapshot->bracketRound);
        $this->assertSame((int) $final->bracket_match, $snapshot->bracketMatch);
        $this->assertSame(BracketGamePurpose::Main->value, $snapshot->purpose);
        $this->assertFalse($snapshot->isBye);
        $this->assertSame(GameStatus::Finished->value, $snapshot->status);
        $this->assertSame('Final', $snapshot->round);
        $this->assertSame((int) $final->entry1_id, $snapshot->entry1Id);
        $this->assertSame((int) $final->entry2_id, $snapshot->entry2Id);
        $this->assertSame((int) $final->winner_entry_id, $snapshot->winnerEntryId);
        $this->assertSame((int) $final->entry2_id, $snapshot->loserEntryId());
    }

    public function test_from_team_tie_maps_geometry_without_player_ids(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()->where('competition_id', $competition->id)->where('round', 'Final')->sole();

        $snapshot = CompetitionFinalMatchSnapshot::fromTeamTie($final);

        $this->assertSame((int) $final->bracket_round, $snapshot->bracketRound);
        $this->assertSame((int) $final->bracket_match, $snapshot->bracketMatch);
        $this->assertSame(BracketGamePurpose::Main->value, $snapshot->purpose);
        $this->assertFalse($snapshot->isBye);
        $this->assertSame(TeamTieStatus::Pending->value, $snapshot->status);
        $this->assertSame('Final', $snapshot->round);
        $this->assertContains($snapshot->entry1Id, [(int) $entries[0]->id, (int) $entries[1]->id]);
        $this->assertContains($snapshot->entry2Id, [(int) $entries[0]->id, (int) $entries[1]->id]);
        $this->assertNull($snapshot->winnerEntryId);
        $this->assertNull($snapshot->loserEntryId());
    }
}
