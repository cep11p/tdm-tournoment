<?php

namespace App\Enums;

enum AuditAction: string
{
    case GROUPS_REGENERATED = 'groups.regenerated';
    case BRACKET_CREATED = 'bracket.created';
    case BRACKET_ROUND_ADVANCED = 'bracket.round_advanced';
    case GAME_SET_RECORDED = 'game.set_recorded';
    case GAME_RESULT_CORRECTED = 'game.result_corrected';
    case GROUP_PLAYER_STATUS_CHANGED = 'groups.player_status_changed';
    case GROUP_MANUAL_TIEBREAK_APPLIED = 'groups.manual_tiebreak_applied';

    public function label(): string
    {
        return match ($this) {
            self::GROUPS_REGENERATED => 'Regeneración de grupos',
            self::BRACKET_CREATED => 'Generación de llave',
            self::BRACKET_ROUND_ADVANCED => 'Avance de ronda',
            self::GAME_SET_RECORDED => 'Registro de set',
            self::GAME_RESULT_CORRECTED => 'Corrección de resultado',
            self::GROUP_PLAYER_STATUS_CHANGED => 'Cambio de estado de jugador',
            self::GROUP_MANUAL_TIEBREAK_APPLIED => 'Desempate manual',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this) {
            self::GROUPS_REGENERATED,
            self::GROUP_PLAYER_STATUS_CHANGED,
            self::GROUP_MANUAL_TIEBREAK_APPLIED => 'Grupos',
            self::BRACKET_CREATED,
            self::BRACKET_ROUND_ADVANCED => 'Llave',
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
            'groups' => 'Grupos',
            'bracket' => 'Llave',
            'games' => 'Partidos',
            default => 'Auditoría',
        };
    }
}
