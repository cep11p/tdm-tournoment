<?php

namespace App\Enums;

enum Permission: string
{
    case TournamentsView = 'tournaments.view';
    case TournamentsManage = 'tournaments.manage';

    case CompetitionsView = 'competitions.view';
    case CompetitionsManage = 'competitions.manage';

    case PlayersView = 'players.view';
    case PlayersManage = 'players.manage';

    case RegistrationsView = 'registrations.view';
    case RegistrationsManage = 'registrations.manage';

    case GroupsView = 'groups.view';
    case GroupsManage = 'groups.manage';
    case GroupsRegenerate = 'groups.regenerate';

    case StandingsView = 'standings.view';

    case MatchesView = 'matches.view';
    case MatchesCreate = 'matches.create';
    case MatchesDelete = 'matches.delete';
    case MatchesRecordResult = 'matches.record_result';
    case MatchesCorrectResult = 'matches.correct_result';

    case BracketsView = 'brackets.view';
    case BracketsManage = 'brackets.manage';
    case BracketsAdvanceRound = 'brackets.advance_round';

    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';

    case AuditView = 'audit.view';

    case UsersManage = 'users.manage';

    /**
     * @return list<Permission>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
