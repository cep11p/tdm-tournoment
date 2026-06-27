<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Player\CreatePlayerAction;
use App\Actions\Player\DeletePlayerAction;
use App\Actions\Player\UpdatePlayerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Player\StorePlayerRequest;
use App\Http\Requests\Player\UpdatePlayerRequest;
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
        $includeInactive = $request->boolean('include_inactive');
        $sort = (string) $request->query('sort', '-id');

        $query = Player::query()
            ->when(! $includeInactive, fn ($builder) => $builder->active())
            ->when($q !== '', function ($builder) use ($q): void {
                $builder->where(function ($subQuery) use ($q): void {
                    $subQuery->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('nickname', 'like', "%{$q}%");
                });
            });

        match ($sort) {
            'last_name' => $query->orderBy('last_name')->orderBy('first_name'),
            '-last_name' => $query->orderByDesc('last_name')->orderByDesc('first_name'),
            'id' => $query->orderBy('id'),
            default => $query->orderByDesc('id'),
        };

        if ($request->has('page')) {
            $perPage = min(
                (int) $request->query('per_page', config('api.pagination.default_per_page', 15)),
                (int) config('api.pagination.max_per_page', 100),
            );
            $perPage = max(1, $perPage);

            return PlayerResource::collection(
                $query->paginate($perPage)->withQueryString(),
            );
        }

        return PlayerResource::collection($query->get());
    }

    public function show(Player $player): PlayerResource
    {
        $player->loadCount([
            'registrations',
            'groupPlayers',
            'gamesAsPlayer1',
            'gamesAsPlayer2',
        ]);

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

    public function update(
        UpdatePlayerRequest $request,
        Player $player,
        UpdatePlayerAction $updatePlayer
    ): PlayerResource {
        return new PlayerResource(
            $updatePlayer($player, $request->validated()),
        );
    }

    public function destroy(Player $player, DeletePlayerAction $deletePlayer): Response
    {
        $deletePlayer($player);

        return response()->noContent();
    }
}
