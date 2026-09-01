<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Competition\UpdateCompetitionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Competition\StoreCompetitionRequest;
use App\Http\Requests\Competition\UpdateCompetitionRequest;
use App\Http\Resources\Competition\CompetitionResource;
use App\Enums\TeamTieStatus;
use App\Models\Competition;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CompetitionController extends Controller
{
    public function index(Tournament $tournament): AnonymousResourceCollection
    {
        $competitions = $tournament->competitions()
            ->with('categoryModel:id,name,slug')
            ->withCount(['entries as registrations_count', 'games'])
            ->latest('id')
            ->get();

        return CompetitionResource::collection($competitions);
    }

    public function show(Competition $competition): CompetitionResource
    {
        $competition->load('categoryModel:id,name,slug');
        $competition->load(['teamTieFormat.slots' => fn ($query) => $query->orderBy('slot_order')]);
        $competition->loadCount([
            'entries as registrations_count',
            'games',
            'teamTies as team_ties_count',
            'teamTies as finished_team_ties_count' => fn ($query) => $query->where('status', TeamTieStatus::Finished),
        ]);

        return new CompetitionResource($competition);
    }

    public function store(
        StoreCompetitionRequest $request,
        Tournament $tournament,
        CreateCompetitionAction $createCompetition
    ): JsonResponse {
        $competition = $createCompetition([
            ...$request->validated(),
            'tournament_id' => $tournament->id,
        ]);

        return (new CompetitionResource($competition->load([
            'categoryModel:id,name,slug',
            'teamTieFormat.slots' => fn ($query) => $query->orderBy('slot_order'),
        ])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateCompetitionRequest $request,
        Competition $competition,
        UpdateCompetitionAction $updateCompetition
    ): CompetitionResource {
        return new CompetitionResource(
            $updateCompetition($competition, $request->validated())
                ->load([
                    'categoryModel:id,name,slug',
                    'teamTieFormat.slots' => fn ($query) => $query->orderBy('slot_order'),
                ])
        );
    }
}
