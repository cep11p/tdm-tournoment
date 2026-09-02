<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\BuildCompetitionGroupsPrintAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Group\PrintCompetitionGroupsResource;
use App\Models\Competition;

class CompetitionGroupsPrintController extends Controller
{
    public function __invoke(
        Competition $competition,
        BuildCompetitionGroupsPrintAction $buildCompetitionGroupsPrint,
    ): PrintCompetitionGroupsResource {
        return new PrintCompetitionGroupsResource($buildCompetitionGroupsPrint($competition));
    }
}
