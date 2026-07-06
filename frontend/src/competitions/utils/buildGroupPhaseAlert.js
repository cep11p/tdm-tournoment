const isInactiveStanding = (standing) => (standing?.group_player_status ?? 'active') !== 'active'

const isPendingGame = (game) => game?.status === 'pending' || game?.status === 'in_progress'

const buildInactivePlayersLabel = (inactivePlayers) => {
  if (inactivePlayers.length === 0) {
    return null
  }

  const withdrawnCount = inactivePlayers.filter(
    (standing) => standing.group_player_status === 'withdrawn',
  ).length
  const disqualifiedCount = inactivePlayers.filter(
    (standing) => standing.group_player_status === 'disqualified',
  ).length

  const parts = []

  if (withdrawnCount > 0) {
    parts.push(`${withdrawnCount} retirado${withdrawnCount === 1 ? '' : 's'}`)
  }

  if (disqualifiedCount > 0) {
    parts.push(`${disqualifiedCount} descalificado${disqualifiedCount === 1 ? '' : 's'}`)
  }

  const detail = parts.length > 0 ? ` (${parts.join(', ')})` : ''

  return `${inactivePlayers.length} jugador${inactivePlayers.length === 1 ? '' : 'es'} con baja${detail}`
}

export function buildGroupPhaseAlert({ group, standings = [], meta = {}, games = [] }) {
  const normalizedStandings = Array.isArray(standings) ? standings : []
  const normalizedMeta = meta ?? {}
  const normalizedGames = Array.isArray(games) ? games : []

  const inactivePlayers = normalizedStandings.filter(isInactiveStanding)
  const pendingGamesCount = normalizedGames.filter(isPendingGame).length

  const isProvisional = Boolean(normalizedMeta.standings_are_provisional)
  const hasPendingManualTiebreak =
    !isProvisional && Boolean(normalizedMeta.requires_manual_tiebreak)
  const staleManualTiebreaks = normalizedMeta.stale_manual_tiebreaks ?? []
  const hasStaleManualTiebreaks = !isProvisional && staleManualTiebreaks.length > 0
  const hasAppliedManualTiebreaks = Boolean(normalizedMeta.has_manual_tiebreaks)

  const hasGroupGames = normalizedGames.length > 0
  const hasStandings = normalizedStandings.length > 0

  const alerts = []

  if (hasAppliedManualTiebreaks) {
    alerts.push({ label: 'Desempate manual aplicado', type: 'info' })
  }

  if (hasStaleManualTiebreaks) {
    alerts.push({
      label:
        staleManualTiebreaks.length === 1
          ? 'Desempate desactualizado'
          : `${staleManualTiebreaks.length} desempates desactualizados`,
      type: 'warning',
    })
  }

  const inactiveLabel = buildInactivePlayersLabel(inactivePlayers)

  if (inactiveLabel) {
    alerts.push({ label: inactiveLabel, type: 'muted' })
  }

  if (pendingGamesCount > 0) {
    alerts.push({
      label: `${pendingGamesCount} partido${pendingGamesCount === 1 ? '' : 's'} pendiente${pendingGamesCount === 1 ? '' : 's'}`,
      type: 'info',
    })
  }

  let primaryLabel
  let primaryType
  let needsAttention
  let isReady
  let highlightLink = null

  if (hasPendingManualTiebreak) {
    primaryLabel = 'Empate manual pendiente'
    primaryType = 'warning'
    needsAttention = true
    isReady = false
    highlightLink = 'standings'
  } else if (hasStaleManualTiebreaks) {
    primaryLabel = 'Desempate desactualizado'
    primaryType = 'warning'
    needsAttention = true
    isReady = false
    highlightLink = 'standings'
  } else if (pendingGamesCount > 0) {
    primaryLabel = 'Partidos pendientes'
    primaryType = 'info'
    needsAttention = true
    isReady = false
    highlightLink = 'group'
  } else if (inactivePlayers.length > 0) {
    primaryLabel = 'Con bajas'
    primaryType = 'muted'
    needsAttention = false
    isReady = false
  } else if (!hasGroupGames) {
    primaryLabel = 'Sin round robin'
    primaryType = 'muted'
    needsAttention = true
    isReady = false
    highlightLink = 'group'
  } else if (!hasStandings) {
    primaryLabel = 'Sin posiciones'
    primaryType = 'muted'
    needsAttention = true
    isReady = false
    highlightLink = 'group'
  } else {
    primaryLabel = 'Grupo listo'
    primaryType = 'success'
    needsAttention = false
    isReady = true
  }

  return {
    group,
    primaryLabel,
    primaryType,
    needsAttention,
    alerts,
    inactivePlayers,
    pendingGamesCount,
    hasPendingManualTiebreak,
    hasStaleManualTiebreaks,
    hasAppliedManualTiebreaks,
    isReady,
    highlightLink,
  }
}
