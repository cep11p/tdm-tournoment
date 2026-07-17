<?php

use App\Http\Controllers\Api\V1\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ClubController;
use App\Http\Controllers\Api\V1\BracketNextRoundController;
use App\Http\Controllers\Api\V1\CompetitionBracketController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\CompetitionStandingsController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupRandomGenerateController;
use App\Http\Controllers\Api\V1\GroupRandomRegenerateController;
use App\Http\Controllers\Api\V1\GroupPlayerController;
use App\Http\Controllers\Api\V1\GroupManualTiebreakController;
use App\Http\Controllers\Api\V1\GroupPlayerStatusController;
use App\Http\Controllers\Api\V1\GroupRoundRobinGameController;
use App\Http\Controllers\Api\V1\GroupStandingsController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\TournamentController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('api.version_prefix', 'v1'))
    ->group(function (): void {
        Route::middleware('auth.keycloak')
            ->get('me', AuthenticatedUserController::class)
            ->name('me');

        Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
        Route::get('tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
        Route::middleware('auth.tournaments.manage')
            ->post('tournaments', [TournamentController::class, 'store'])
            ->name('tournaments.store');
        Route::middleware('auth.tournaments.manage')
            ->match(['put', 'patch'], 'tournaments/{tournament}', [TournamentController::class, 'update'])
            ->name('tournaments.update');

        Route::get('tournaments/{tournament}/competitions', [CompetitionController::class, 'index'])
            ->name('tournaments.competitions.index');
        Route::middleware('auth.competitions.manage')
            ->post('tournaments/{tournament}/competitions', [CompetitionController::class, 'store'])
            ->name('tournaments.competitions.store');

        Route::get('competitions/{competition}', [CompetitionController::class, 'show'])
            ->name('competitions.show');
        Route::middleware('auth.competitions.manage')
            ->match(['put', 'patch'], 'competitions/{competition}', [CompetitionController::class, 'update'])
            ->name('competitions.update');

        Route::get('competitions/{competition}/standings', [CompetitionStandingsController::class, 'index'])
            ->name('competitions.standings.index');

        Route::get('competitions/{competition}/bracket', [CompetitionBracketController::class, 'show'])
            ->name('competitions.bracket.show');

        Route::post('competitions/{competition}/bracket', [CompetitionBracketController::class, 'store'])
            ->name('competitions.bracket.store');

        Route::post('brackets/{bracket}/next-round', [BracketNextRoundController::class, 'store'])
            ->name('brackets.next-round.store');

        Route::apiResource('players', PlayerController::class);

        Route::get('categories', [CategoryController::class, 'index'])
            ->name('categories.index');

        Route::get('clubs', [ClubController::class, 'index'])
            ->name('clubs.index');

        Route::post('competitions/{competition}/registrations/bulk', [RegistrationController::class, 'bulkStore'])
            ->name('competitions.registrations.bulk');

        Route::apiResource('competitions.registrations', RegistrationController::class)
            ->only(['store', 'index']);

        Route::post(
            'competitions/{competition}/groups/random-generate',
            GroupRandomGenerateController::class,
        )->name('competitions.groups.random-generate');

        Route::post(
            'competitions/{competition}/groups/regenerate-random',
            GroupRandomRegenerateController::class,
        )->name('competitions.groups.regenerate-random');

        Route::apiResource('competitions.groups', GroupController::class)
            ->only(['store', 'index']);

        Route::apiResource('groups.players', GroupPlayerController::class)
            ->only(['store', 'index']);

        Route::post('groups/{group}/round-robin-games', [GroupRoundRobinGameController::class, 'store'])
            ->name('groups.round-robin-games.store');

        Route::get('groups/{group}/standings', [GroupStandingsController::class, 'index'])
            ->name('groups.standings.index');

        Route::post('groups/{group}/manual-tiebreaks', [GroupManualTiebreakController::class, 'store'])
            ->name('groups.manual-tiebreaks.store');

        Route::post('groups/{group}/player-status', [GroupPlayerStatusController::class, 'store'])
            ->name('groups.player-status.store');

        Route::apiResource('competitions.games', GameController::class)
            ->only(['store', 'index']);

        Route::apiResource('games', GameController::class)
            ->only(['show', 'destroy']);

        Route::post('games/{game}/sets', [GameController::class, 'storeSet'])
            ->middleware('auth.matches.record_result')
            ->name('games.sets.store');
    });
