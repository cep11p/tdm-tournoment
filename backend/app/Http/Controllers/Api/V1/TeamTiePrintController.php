<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TeamTie\BuildPrintTeamTieAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamTie\PrintTeamTieResource;
use App\Models\TeamTie;

class TeamTiePrintController extends Controller
{
    public function __invoke(
        TeamTie $teamTie,
        BuildPrintTeamTieAction $buildPrintTeamTie,
    ): PrintTeamTieResource {
        return new PrintTeamTieResource($buildPrintTeamTie($teamTie));
    }
}
