<?php

namespace App\Enums;

enum AuditAction: string
{
    case TOURNAMENT_CREATED = 'tournament.created';
    case TOURNAMENT_UPDATED = 'tournament.updated';
    case TOURNAMENT_CLOSED = 'tournament.closed';
    case COMPETITION_CREATED = 'competition.created';
    case COMPETITION_UPDATED = 'competition.updated';
    case PLAYER_CREATED = 'player.created';
    case PLAYER_UPDATED = 'player.updated';
    case PLAYER_DEACTIVATED = 'player.deactivated';
    case PLAYER_DELETED = 'player.deleted';
    case REGISTRATION_CREATED = 'registration.created';
    case REGISTRATION_BULK_CREATED = 'registration.bulk_created';
    case GROUPS_GENERATED = 'groups.generated';
    case GROUP_CREATED = 'group.created';
    case GROUP_PLAYER_ASSIGNED = 'group.player_assigned';
    case GROUPS_ROUND_ROBIN_GENERATED = 'groups.round_robin_generated';
    case GROUPS_REGENERATED = 'groups.regenerated';
    case BRACKET_CREATED = 'bracket.created';
    case BRACKET_ROUND_ADVANCED = 'bracket.round_advanced';
    case GAME_CREATED = 'game.created';
    case GAME_DELETED = 'game.deleted';
    case GAME_SET_RECORDED = 'game.set_recorded';
    case GAME_RESULT_CORRECTED = 'game.result_corrected';
    case GROUP_PLAYER_STATUS_CHANGED = 'groups.player_status_changed';
    case GROUP_MANUAL_TIEBREAK_APPLIED = 'groups.manual_tiebreak_applied';

    public function label(): string
    {
        return match ($this) {
            self::TOURNAMENT_CREATED => 'Creación de torneo',
            self::TOURNAMENT_UPDATED => 'Actualización de torneo',
            self::TOURNAMENT_CLOSED => 'Cierre de torneo',
            self::COMPETITION_CREATED => 'Creación de competencia',
            self::COMPETITION_UPDATED => 'Actualización de competencia',
            self::PLAYER_CREATED => 'Creación de jugador',
            self::PLAYER_UPDATED => 'Actualización de jugador',
            self::PLAYER_DEACTIVATED => 'Desactivación de jugador',
            self::PLAYER_DELETED => 'Eliminación de jugador',
            self::REGISTRATION_CREATED => 'Inscripción de jugador',
            self::REGISTRATION_BULK_CREATED => 'Inscripción masiva',
            self::GROUPS_GENERATED => 'Generación de grupos',
            self::GROUP_CREATED => 'Creación de grupo',
            self::GROUP_PLAYER_ASSIGNED => 'Asignación de jugador a grupo',
            self::GROUPS_ROUND_ROBIN_GENERATED => 'Generación de todos contra todos',
            self::GROUPS_REGENERATED => 'Regeneración de grupos',
            self::BRACKET_CREATED => 'Generación de llave',
            self::BRACKET_ROUND_ADVANCED => 'Avance de ronda',
            self::GAME_CREATED => 'Creación de partido',
            self::GAME_DELETED => 'Eliminación de partido',
            self::GAME_SET_RECORDED => 'Registro de set',
            self::GAME_RESULT_CORRECTED => 'Corrección de resultado',
            self::GROUP_PLAYER_STATUS_CHANGED => 'Cambio de estado de jugador',
            self::GROUP_MANUAL_TIEBREAK_APPLIED => 'Desempate manual',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this) {
            self::TOURNAMENT_CREATED,
            self::TOURNAMENT_UPDATED,
            self::TOURNAMENT_CLOSED => 'Torneos',
            self::COMPETITION_CREATED,
            self::COMPETITION_UPDATED => 'Competencias',
            self::PLAYER_CREATED,
            self::PLAYER_UPDATED,
            self::PLAYER_DEACTIVATED,
            self::PLAYER_DELETED => 'Jugadores',
            self::REGISTRATION_CREATED,
            self::REGISTRATION_BULK_CREATED => 'Inscripciones',
            self::GROUPS_GENERATED,
            self::GROUP_CREATED,
            self::GROUP_PLAYER_ASSIGNED,
            self::GROUPS_ROUND_ROBIN_GENERATED,
            self::GROUPS_REGENERATED,
            self::GROUP_PLAYER_STATUS_CHANGED,
            self::GROUP_MANUAL_TIEBREAK_APPLIED => 'Grupos',
            self::BRACKET_CREATED,
            self::BRACKET_ROUND_ADVANCED => 'Llave',
            self::GAME_CREATED,
            self::GAME_DELETED,
            self::GAME_SET_RECORDED,
            self::GAME_RESULT_CORRECTED => 'Partidos',
        };
    }

    public static function labelFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Acción desconocida';
        }

        return self::tryFrom($code)?->label() ?? $code;
    }

    public static function categoryLabelFor(?string $code, ?string $logName = null): string
    {
        $fromAction = self::tryFrom((string) $code)?->categoryLabel();

        if ($fromAction !== null) {
            return $fromAction;
        }

        return match ($logName) {
            'tournaments' => 'Torneos',
            'competitions' => 'Competencias',
            'players' => 'Jugadores',
            'registrations' => 'Inscripciones',
            'groups' => 'Grupos',
            'bracket' => 'Llave',
            'games' => 'Partidos',
            default => 'Auditoría',
        };
    }
}
