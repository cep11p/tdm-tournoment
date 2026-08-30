<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\RegenerateRandomGroupsForCompetitionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Group\GenerateRandomGroupsRequest;
use App\Http\Resources\Group\GroupResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GroupRandomRegenerateController extends Controller
{
    public function __invoke(
        GenerateRandomGroupsRequest $request,
        Competition $competition,
        RegenerateRandomGroupsForCompetitionAction $regenerateRandomGroups,
    ): JsonResponse {
        $result = $regenerateRandomGroups(
            $competition,
            (int) $request->validated('groups_count'),
        );

        return response()->json([
            'message' => 'Grupos regenerados correctamente.',
            'groups_removed' => $result['groups_removed'],
            'games_removed' => $result['games_removed'],
            'team_ties_removed' => $result['team_ties_removed'] ?? 0,
            'bracket_removed' => $result['bracket_removed'],
            'groups_created' => $result['groups_created'],
            'players_assigned' => $result['players_assigned'],
            'games_created' => $result['games_created'],
            'team_ties_created' => $result['team_ties_created'] ?? 0,
            'groups' => GroupResource::collection($result['groups'])->resolve(),
        ], Response::HTTP_CREATED);
    }
}
