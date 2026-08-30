<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamTie\TeamTieResource;
use App\Models\TeamTie;

class TeamTieController extends Controller
{
    public function show(TeamTie $teamTie): TeamTieResource
    {
        $teamTie->load(TeamTie::DISPLAY_RELATIONS);

        return new TeamTieResource($teamTie);
    }
}
