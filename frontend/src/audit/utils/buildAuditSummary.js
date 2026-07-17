const STATUS_LABELS = {
  pending: 'pendiente',
  in_progress: 'partido en curso',
  finished: 'partido finalizado',
}

const FIELD_LABELS = {
  name: 'nombre',
  location: 'ubicación',
  start_date: 'fecha de inicio',
  end_date: 'fecha de fin',
  status: 'estado',
  type: 'tipo',
  format: 'formato',
  category_id: 'categoría',
  category_name: 'nombre de categoría',
  points_per_set: 'puntos por set',
  qualified_per_group: 'clasificados por grupo',
  group_stage_best_of: 'mejor de en grupos',
  knockout_stage_best_of: 'mejor de en eliminatoria',
  semifinal_best_of: 'mejor de en semifinal',
  final_best_of: 'mejor de en final',
  first_name: 'nombre',
  last_name: 'apellido',
  nickname: 'apodo',
  club_id: 'club',
  club_name: 'nombre de club',
  active: 'activo',
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

function formatChangedFields(fields) {
  if (!Array.isArray(fields) || fields.length === 0) {
    return null
  }

  const labels = fields
    .map((field) => FIELD_LABELS[field] ?? field)
    .filter(Boolean)

  if (labels.length === 0) {
    return 'Se modificaron campos'
  }

  if (labels.length === 1) {
    return `Se modificó ${labels[0]}`
  }

  if (labels.length === 2) {
    return `Se modificaron ${labels[0]} y ${labels[1]}`
  }

  return `Se modificaron ${labels.slice(0, -1).join(', ')} y ${labels[labels.length - 1]}`
}

function displayName(auditLog, fallback = 'Entidad') {
  return (
    auditLog?.summary?.tournament_name
    ?? auditLog?.summary?.competition_name
    ?? auditLog?.summary?.player_name
    ?? auditLog?.context?.tournament_name
    ?? auditLog?.context?.competition_name
    ?? auditLog?.context?.player_name
    ?? auditLog?.subject?.label
    ?? fallback
  )
}

export function buildAuditSummary(auditLog) {
  const action = auditLog?.action
  const summary = auditLog?.summary ?? {}

  switch (action) {
    case 'tournament.created': {
      const name = summary.tournament_name ?? displayName(auditLog, 'Torneo')
      return `Torneo "${name}" creado`
    }

    case 'tournament.updated':
      return formatChangedFields(summary.changed_fields) ?? 'Torneo actualizado'

    case 'competition.created': {
      const name = summary.competition_name ?? displayName(auditLog, 'Competencia')
      return `Competencia "${name}" creada`
    }

    case 'competition.updated':
      return formatChangedFields(summary.changed_fields) ?? 'Competencia actualizada'

    case 'player.created': {
      const name = summary.player_name ?? displayName(auditLog, 'Jugador')
      return `${name} registrado`
    }

    case 'player.updated':
      return formatChangedFields(summary.changed_fields) ?? `${displayName(auditLog, 'Jugador')} actualizado`

    case 'player.deactivated': {
      const name = summary.player_name ?? displayName(auditLog, 'Jugador')
      return `${name} desactivado`
    }

    case 'player.deleted': {
      const name = summary.player_name ?? displayName(auditLog, 'Jugador')
      return `${name} eliminado`
    }

    case 'registration.created': {
      const playerName = summary.player_name ?? 'Jugador'
      const competitionName = auditLog?.context?.competition_name ?? 'competencia'
      return `${playerName} inscripto en ${competitionName}`
    }

    case 'registration.bulk_created': {
      const created = formatCount(summary.created_count, 'inscripción creada', 'inscripciones creadas')
      const skipped = formatCount(summary.skipped_count, 'omitida', 'omitidas')

      return [created, skipped ? `${skipped} omitidas` : null].filter(Boolean).join(' · ')
    }

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
