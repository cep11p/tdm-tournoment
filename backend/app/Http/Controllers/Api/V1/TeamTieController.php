<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamTie\TeamTieResource;
use App\Models\TeamTie;

class TeamTieController extends Controller
{
    private const SHOW_RELATIONS = [
        ...TeamTie::DISPLAY_RELATIONS,
        'teamTieGames.game',
        'teamTieGames.members.competitionEntryMember.player:id,first_name,last_name,nickname',
    ];

    public function show(TeamTie $teamTie): TeamTieResource
    {
        $teamTie->load([
            ...self::SHOW_RELATIONS,
            'competition.tournament:id,name',
            'group:id,name',
        ]);

        return new TeamTieResource($teamTie);
    }
}
