export function countGroupParticipants(group) {
  return Array.isArray(group?.group_players) ? group.group_players.length : 0
}

export function formatGroupScheduleProgress(meta) {
  if (
    meta == null ||
    (meta.completed_games_count == null && meta.total_games_count == null)
  ) {
    return null
  }

  const completed = Number(meta.completed_games_count ?? 0)
  const total = Number(meta.total_games_count ?? 0)

  return `${completed}/${total}`
}

export function groupPhasePrimaryAction(summary) {
  if (summary?.hasPendingManualTiebreak || summary?.hasStaleManualTiebreaks) {
    return { label: 'Resolver empate', intent: 'standings' }
  }

  if ((summary?.pendingGamesCount ?? 0) > 0) {
    const isTeam = String(summary?.primaryLabel ?? '').includes('Enfrentamiento')

    return {
      label: isTeam ? 'Ver enfrentamientos' : 'Ver partidos',
      intent: 'group',
    }
  }

  if (summary?.isReady) {
    return { label: 'Ver posiciones', intent: 'positions' }
  }

  return { label: 'Abrir grupo', intent: 'group' }
}
