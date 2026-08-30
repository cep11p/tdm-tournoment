<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamTie\TeamTieResource;
use App\Models\Group;
use App\Models\TeamTie;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupTeamTieController extends Controller
{
    private const TEAM_TIE_RELATIONS = TeamTie::DISPLAY_RELATIONS;

    public function index(Group $group): AnonymousResourceCollection
    {
        $teamTies = $group->teamTies()
            ->with(self::TEAM_TIE_RELATIONS)
            ->orderByRaw('group_round IS NULL')
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id')
            ->get();

        return TeamTieResource::collection($teamTies);
    }
}
