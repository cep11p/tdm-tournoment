<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlayingTable\CreatePlayingTableAction;
use App\Actions\PlayingTable\DeletePlayingTableAction;
use App\Actions\PlayingTable\UpdatePlayingTableAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlayingTable\StorePlayingTableRequest;
use App\Http\Requests\PlayingTable\UpdatePlayingTableRequest;
use App\Http\Resources\PlayingTable\PlayingTableResource;
use App\Models\PlayingTable;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlayingTableController extends Controller
{
    public function index(Tournament $tournament): AnonymousResourceCollection
    {
        return PlayingTableResource::collection(
            $tournament->playingTables()->get(),
        );
    }

    public function store(
        StorePlayingTableRequest $request,
        Tournament $tournament,
        CreatePlayingTableAction $createPlayingTable,
    ): JsonResponse {
        $playingTable = $createPlayingTable($tournament, $request->validated());

        return (new PlayingTableResource($playingTable))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdatePlayingTableRequest $request,
        Tournament $tournament,
        PlayingTable $playingTable,
        UpdatePlayingTableAction $updatePlayingTable,
    ): PlayingTableResource {
        return new PlayingTableResource(
            $updatePlayingTable($tournament, $playingTable, $request->validated()),
        );
    }

    public function destroy(
        Tournament $tournament,
        PlayingTable $playingTable,
        DeletePlayingTableAction $deletePlayingTable,
    ): Response {
        $deletePlayingTable($tournament, $playingTable);

        return response()->noContent();
    }
}
