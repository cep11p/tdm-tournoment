<?php

namespace App\Enums;

enum AuditAction: string
{
    case GROUPS_REGENERATED = 'groups.regenerated';
    case BRACKET_CREATED = 'bracket.created';
    case BRACKET_ROUND_ADVANCED = 'bracket.round_advanced';
    case GAME_SET_RECORDED = 'game.set_recorded';
    case GROUP_PLAYER_STATUS_CHANGED = 'groups.player_status_changed';
    case GROUP_MANUAL_TIEBREAK_APPLIED = 'groups.manual_tiebreak_applied';
}
