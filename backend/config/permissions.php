<?php

use App\Enums\Permission;

return [

    /*
    |--------------------------------------------------------------------------
    | Keycloak role → application permissions
    |--------------------------------------------------------------------------
    |
    | Roles are read from the access token on each request and translated into
    | granular permissions. Unknown realm roles (e.g. offline_access) are ignored.
    |
    */

    'roles' => [
        'admin' => Permission::values(),

        'organizer' => [
            Permission::TournamentsView->value,
            Permission::TournamentsManage->value,
            Permission::CompetitionsView->value,
            Permission::CompetitionsManage->value,
            Permission::PlayersView->value,
            Permission::PlayersManage->value,
            Permission::RegistrationsView->value,
            Permission::RegistrationsManage->value,
            Permission::GroupsView->value,
            Permission::GroupsManage->value,
            Permission::GroupsRegenerate->value,
            Permission::StandingsView->value,
            Permission::MatchesView->value,
            Permission::MatchesCreate->value,
            Permission::MatchesDelete->value,
            Permission::MatchesRecordResult->value,
            Permission::BracketsView->value,
            Permission::BracketsManage->value,
            Permission::BracketsAdvanceRound->value,
            Permission::CatalogView->value,
        ],

        'scorekeeper' => [
            Permission::TournamentsView->value,
            Permission::CompetitionsView->value,
            Permission::PlayersView->value,
            Permission::RegistrationsView->value,
            Permission::GroupsView->value,
            Permission::StandingsView->value,
            Permission::MatchesView->value,
            Permission::MatchesRecordResult->value,
            Permission::BracketsView->value,
            Permission::CatalogView->value,
        ],

        'player' => [
            Permission::TournamentsView->value,
            Permission::CompetitionsView->value,
            Permission::PlayersView->value,
            Permission::RegistrationsView->value,
            Permission::GroupsView->value,
            Permission::StandingsView->value,
            Permission::MatchesView->value,
            Permission::BracketsView->value,
            Permission::CatalogView->value,
        ],
    ],

];
