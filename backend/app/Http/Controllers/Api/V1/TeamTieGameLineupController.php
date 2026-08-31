<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TeamTie\SetTeamTieGameLineupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamTie\StoreTeamTieGameLineupRequest;
use App\Http\Resources\TeamTie\TeamTieGameResource;
use App\Models\TeamTieGame;

class TeamTieGameLineupController extends Controller
{
    public function store(
        StoreTeamTieGameLineupRequest $request,
        TeamTieGame $teamTieGame,
        SetTeamTieGameLineupAction $setLineup,
    ): TeamTieGameResource {
        $teamTieGame = $setLineup($teamTieGame, $request->validated());

        return new TeamTieGameResource($teamTieGame);
    }
}
