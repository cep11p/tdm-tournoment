<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Player\CreatePlayerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Resources\Player\PlayerResource;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlayerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));

        $players = Player::query()
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($subQuery) use ($q): void {
                    $subQuery->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('nickname', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->get();

        return PlayerResource::collection($players);
    }

    public function show(Player $player): PlayerResource
    {
        return new PlayerResource($player);
    }

    public function store(
        StorePlayerRequest $request,
        CreatePlayerAction $createPlayer
    ): JsonResponse {
        $player = $createPlayer($request->validated());

        return (new PlayerResource($player))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
