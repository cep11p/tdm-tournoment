<?php

namespace App\Actions\TeamTie;

use App\Data\TeamTie\PrintTeamTieData;
use App\Models\TeamTie;
use App\Support\TeamTie\TeamTiePrintStructureBuilder;

final class BuildPrintTeamTieAction
{
    public function __construct(
        private readonly TeamTiePrintStructureBuilder $builder,
    ) {}

    public function __invoke(TeamTie $teamTie): PrintTeamTieData
    {
        $teamTie->load([
            'competition.tournament:id,name',
            'group:id,name',
            'entry1.members.player:id,first_name,last_name,nickname',
            'entry2.members.player:id,first_name,last_name,nickname',
            'winnerEntry.members.player:id,first_name,last_name,nickname',
            'teamTieGames' => fn ($query) => $query->orderBy('slot_order'),
            'teamTieGames.game',
            'teamTieGames.members.competitionEntryMember.player:id,first_name,last_name,nickname',
        ]);

        return $this->builder->build($teamTie);
    }
}
