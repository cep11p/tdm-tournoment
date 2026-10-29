const scheduleUnit = (isTeam, count) => {
  const unit = isTeam ? 'enfrentamiento' : 'partido'

  return count === 1 ? unit : `${unit}s`
}

/**
 * Grupo que tuvo al menos 2 participantes y, por bajas, quedó con menos de 2
 * activos: no necesita partidos (su único activo clasifica sin disputarlos).
 * Un grupo con menos de 2 participantes sigue necesitando integrantes.
 */
export function isScheduleNotRequiredByWithdrawals({
  participantCount = 0,
  activeParticipantCount = 0,
} = {}) {
  return participantCount >= 2 && activeParticipantCount < 2
}

/**
 * Mensaje para un grupo que no necesita partidos por bajas.
 */
export function buildScheduleNotRequiredLabel({ activeParticipantCount = 0, isTeam = false } = {}) {
  const unit = isTeam ? 'enfrentamientos' : 'partidos'

  if (activeParticipantCount === 1) {
    return `Sin ${unit} necesarios: ${isTeam ? 'el único equipo activo' : 'el único participante activo'} clasifica sin jugar`
  }

  return `Sin ${isTeam ? 'equipos' : 'participantes'} activos: el grupo no aporta clasificados`
}

export function buildGroupOperationalStatus({
  scheduleCount = 0,
  pendingCount = 0,
  requiresManualTiebreak = false,
  hasStaleManualTiebreaks = false,
  isTeam = false,
  participantCount = 0,
  activeParticipantCount = 0,
} = {}) {
  if (requiresManualTiebreak) {
    return { tone: 'warning', label: '⚠ Desempate manual requerido' }
  }

  if (hasStaleManualTiebreaks) {
    return { tone: 'warning', label: '⚠ Desempate desactualizado' }
  }

  if (scheduleCount <= 0) {
    if (isScheduleNotRequiredByWithdrawals({ participantCount, activeParticipantCount })) {
      return activeParticipantCount === 1
        ? { tone: 'success', label: `✓ ${buildScheduleNotRequiredLabel({ activeParticipantCount, isTeam })}` }
        : { tone: 'muted', label: buildScheduleNotRequiredLabel({ activeParticipantCount, isTeam }) }
    }

    return {
      tone: 'muted',
      label: isTeam ? 'Sin enfrentamientos generados' : 'Sin partidos generados',
    }
  }

  if (pendingCount > 0) {
    return {
      tone: 'warning',
      label: `⚠ ${pendingCount} ${scheduleUnit(isTeam, pendingCount)} pendiente${pendingCount === 1 ? '' : 's'}`,
    }
  }

  return {
    tone: 'success',
    label: isTeam
      ? '✓ Todos los enfrentamientos finalizados'
      : '✓ Todos los partidos finalizados',
  }
}
