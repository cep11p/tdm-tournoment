const STATUS_LABELS = {
  pending: 'pendiente',
  in_progress: 'partido en curso',
  finished: 'partido finalizado',
}

function formatCount(value, singular, plural) {
  if (value === undefined || value === null) {
    return null
  }

  const count = Number(value)

  if (Number.isNaN(count)) {
    return null
  }

  return `${count} ${count === 1 ? singular : plural}`
}

function formatStatus(status) {
  if (!status) {
    return null
  }

  return STATUS_LABELS[status] ?? status
}

export function buildAuditSummary(auditLog) {
  const action = auditLog?.action
  const summary = auditLog?.summary ?? {}

  switch (action) {
    case 'groups.regenerated': {
      const parts = [
        formatCount(summary.groups_removed, 'grupo eliminado', 'grupos eliminados'),
        formatCount(summary.groups_created, 'grupo creado', 'grupos creados'),
        formatCount(summary.players_assigned, 'jugador asignado', 'jugadores asignados'),
      ].filter(Boolean)

      return parts.join(' · ')
    }

    case 'bracket.created': {
      const parts = [
        summary.bracket_size ? `Llave de ${summary.bracket_size}` : null,
        formatCount(summary.byes_count, 'BYE', 'BYEs'),
        formatCount(summary.games_created, 'partido', 'partidos'),
      ].filter(Boolean)

      return parts.join(' · ')
    }

    case 'bracket.round_advanced': {
      const parts = [
        summary.generated_round ? `Ronda ${summary.generated_round}` : null,
        formatCount(summary.games_created, 'partido creado', 'partidos creados'),
        formatCount(summary.players_advanced, 'jugador avanzó', 'jugadores avanzaron'),
      ].filter(Boolean)

      return parts.join(' · ')
    }

    case 'game.set_recorded': {
      const setNumber = summary.set_number
      const score =
        summary.player1_score !== undefined && summary.player2_score !== undefined
          ? `${summary.player1_score}–${summary.player2_score}`
          : null
      const status = formatStatus(summary.status ?? auditLog?.new?.status)

      const parts = [
        setNumber && score ? `Set ${setNumber}: ${score}` : null,
        status,
      ].filter(Boolean)

      return parts.join(' · ')
    }

    case 'game.result_corrected': {
      const oldWinner = auditLog?.old?.winner_name ?? (auditLog?.old?.winner_id ? `Jugador #${auditLog.old.winner_id}` : null)
      const newWinner = auditLog?.new?.winner_name ?? (auditLog?.new?.winner_id ? `Jugador #${auditLog.new.winner_id}` : null)
      const beforeCount = summary.sets_count_before ?? auditLog?.old?.sets?.length
      const afterCount = summary.sets_count_after ?? auditLog?.new?.sets?.length

      if (summary.winner_changed && oldWinner && newWinner) {
        const replacement =
          beforeCount !== undefined && afterCount !== undefined
            ? `${beforeCount} sets reemplazados por ${afterCount}`
            : null

        return [`Ganador corregido: ${oldWinner} → ${newWinner}`, replacement].filter(Boolean).join(' · ')
      }

      const updatedCount =
        afterCount !== undefined ? `${afterCount} sets actualizados` : null

      return ['Resultado corregido', 'ganador sin cambios', updatedCount].filter(Boolean).join(' · ')
    }

    case 'groups.player_status_changed': {
      const playerName = summary.player_name ?? 'Jugador'
      const oldStatus = summary.old_status ?? auditLog?.old?.status
      const newStatus = summary.new_status ?? auditLog?.new?.status

      if (oldStatus && newStatus) {
        return `${playerName}: ${oldStatus} → ${newStatus}`
      }

      return playerName
    }

    case 'groups.manual_tiebreak_applied': {
      const parts = [
        formatCount(summary.players_reordered, 'jugador reordenado', 'jugadores reordenados'),
        summary.reason_code ? `motivo ${summary.reason_code}` : null,
      ].filter(Boolean)

      return parts.join(' · ')
    }

    default:
      return ''
  }
}
