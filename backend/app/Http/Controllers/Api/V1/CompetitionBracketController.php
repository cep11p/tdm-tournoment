<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bracket\CreateBracketKnockoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bracket\StoreBracketRequest;
use App\Http\Resources\Bracket\BracketResource;
use App\Models\Competition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CompetitionBracketController extends Controller
{
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
