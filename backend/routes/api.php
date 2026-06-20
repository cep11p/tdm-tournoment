<?php

use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\CompetitionStandingsController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupPlayerController;
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
            ->only(['show']);

        Route::get('competitions/{competition}/standings', [CompetitionStandingsController::class, 'index'])
            ->name('competitions.standings.index');

        Route::apiResource('players', PlayerController::class)
            ->only(['store', 'index', 'show']);

        Route::apiResource('competitions.registrations', RegistrationController::class)
            ->only(['store', 'index']);

        Route::apiResource('competitions.groups', GroupController::class)
            ->only(['store', 'index']);

        Route::apiResource('groups.players', GroupPlayerController::class)
            ->only(['store', 'index']);

        Route::apiResource('competitions.games', GameController::class)
            ->only(['store', 'index']);

        Route::apiResource('games', GameController::class)
            ->only(['show', 'destroy']);

        Route::post('games/{game}/sets', [GameController::class, 'storeSet'])
            ->name('games.sets.store');
    });
