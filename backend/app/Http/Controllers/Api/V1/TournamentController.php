<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tournament\CreateTournamentAction;
use App\Actions\Tournament\UpdateTournamentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tournament\StoreTournamentRequest;
use App\Http\Requests\Tournament\UpdateTournamentRequest;
use App\Http\Resources\Tournament\TournamentResource;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TournamentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tournaments = Tournament::query()
            ->latest('id')
            ->get();

        return TournamentResource::collection($tournaments);
    }

    public function show(Tournament $tournament): TournamentResource
    {
        return new TournamentResource($tournament);
    }

    public function store(
        StoreTournamentRequest $request,
        CreateTournamentAction $createTournament
    ): JsonResponse {
        $tournament = $createTournament($request->validated());

        return (new TournamentResource($tournament))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateTournamentRequest $request,
        Tournament $tournament,
        UpdateTournamentAction $updateTournament
    ): TournamentResource {
        return new TournamentResource(
            $updateTournament($tournament, $request->validated())
        );
    }
}
