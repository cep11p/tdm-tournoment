<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bracket\CreateBracketKnockoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bracket\StoreBracketRequest;
use App\Http\Resources\Bracket\BracketResource;
use App\Models\Competition;
use App\Models\Game;
use App\Models\TeamTie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CompetitionBracketController extends Controller
{
    /**
     * @return array<int, string>
     */
    private function bracketRelations(Competition $competition): array
    {
        if ($competition->isTeam()) {
            return array_map(
                fn (string $relation): string => 'teamTies.'.$relation,
                TeamTie::BRACKET_OVERVIEW_RELATIONS,
            );
        }

        return array_map(
            fn (string $relation): string => 'games.'.$relation,
            Game::DISPLAY_RELATIONS,
        );
    }

    public function show(Competition $competition): JsonResponse
    {
        $bracket = $competition->brackets()
            ->with($this->bracketRelations($competition))
            ->first();

        if ($bracket === null) {
            return response()->json([
                'message' => 'La competencia no tiene un cuadro eliminatorio.',
            ], Response::HTTP_NOT_FOUND);
        }

        return (new BracketResource($bracket))->response();
    }

    public function store(
        StoreBracketRequest $request,
        Competition $competition,
        CreateBracketKnockoutAction $createBracketKnockout
    ): JsonResponse {
        $bracket = $createBracketKnockout($competition, $request->validated());

        return (new BracketResource($bracket))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
