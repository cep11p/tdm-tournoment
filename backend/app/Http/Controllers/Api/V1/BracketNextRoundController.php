<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bracket\GenerateBracketNextRoundAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bracket\BracketResource;
use App\Models\Bracket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BracketNextRoundController extends Controller
{
    public function store(
        Bracket $bracket,
        GenerateBracketNextRoundAction $generateNextRound
    ): JsonResponse {
        $bracket = $generateNextRound($bracket);

        return (new BracketResource($bracket))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
