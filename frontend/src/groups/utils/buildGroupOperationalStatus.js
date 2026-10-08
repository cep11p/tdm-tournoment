const scheduleUnit = (isTeam, count) => {
  const unit = isTeam ? 'enfrentamiento' : 'partido'

  return count === 1 ? unit : `${unit}s`
}

export function buildGroupOperationalStatus({
  scheduleCount = 0,
  pendingCount = 0,
  requiresManualTiebreak = false,
  hasStaleManualTiebreaks = false,
  isTeam = false,
} = {}) {
  if (requiresManualTiebreak) {
    return { tone: 'warning', label: '⚠ Desempate manual requerido' }
  }

  if (hasStaleManualTiebreaks) {
    return { tone: 'warning', label: '⚠ Desempate desactualizado' }
  }

  if (scheduleCount <= 0) {
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
