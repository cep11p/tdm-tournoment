<?php

use App\Http\Controllers\Api\V1\BracketNextRoundController;
use App\Http\Controllers\Api\V1\CompetitionBracketController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\CompetitionStandingsController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupPlayerController;
use App\Http\Controllers\Api\V1\GroupRoundRobinGameController;
use App\Http\Controllers\Api\V1\GroupStandingsController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\TournamentController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('api.version_prefix', 'v1'))
    ->group(function (): void {
        Route::apiResource('tournaments', TournamentController::class)
            ->only(['store', 'index', 'show', 'update']);

        Route::apiResource('tournaments.competitions', CompetitionController::class)
            ->only(['store', 'index']);

        Route::apiResource('competitions', CompetitionController::class)
            ->only(['show', 'update']);

        Route::get('competitions/{competition}/standings', [CompetitionStandingsController::class, 'index'])
            ->name('competitions.standings.index');

        Route::get('competitions/{competition}/bracket', [CompetitionBracketController::class, 'show'])
            ->name('competitions.bracket.show');

        Route::post('competitions/{competition}/bracket', [CompetitionBracketController::class, 'store'])
            ->name('competitions.bracket.store');

        Route::post('brackets/{bracket}/next-round', [BracketNextRoundController::class, 'store'])
            ->name('brackets.next-round.store');

        Route::apiResource('players', PlayerController::class)
            ->only(['store', 'index', 'show']);

        Route::apiResource('competitions.registrations', RegistrationController::class)
            ->only(['store', 'index']);

        Route::apiResource('competitions.groups', GroupController::class)
            ->only(['store', 'index']);

        Route::apiResource('groups.players', GroupPlayerController::class)
            ->only(['store', 'index']);

        Route::post('groups/{group}/round-robin-games', [GroupRoundRobinGameController::class, 'store'])
            ->name('groups.round-robin-games.store');

        Route::get('groups/{group}/standings', [GroupStandingsController::class, 'index'])
            ->name('groups.standings.index');

        Route::apiResource('competitions.games', GameController::class)
            ->only(['store', 'index']);

        Route::apiResource('games', GameController::class)
            ->only(['show', 'destroy']);

        Route::post('games/{game}/sets', [GameController::class, 'storeSet'])
            ->name('games.sets.store');
    });
