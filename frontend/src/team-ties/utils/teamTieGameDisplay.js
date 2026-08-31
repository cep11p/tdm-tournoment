const playerName = (player) => {
  if (!player?.player_id && !player?.id) {
    return 'Sin asignar'
  }

  const name = `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()
  return name || 'Sin asignar'
}

export function getRubberSidePlayers(rubber, sideKey) {
  const lineup = rubber?.lineup?.[sideKey]
  return Array.isArray(lineup) ? lineup : []
}

export function getRubberSideDisplayName(rubber, sideKey) {
  const players = getRubberSidePlayers(rubber, sideKey)

  if (players.length === 0) {
    return 'Sin asignar'
  }

  return players.map((player) => playerName(player)).join(' / ')
}

export function getRubberMatchupLabel(rubber) {
  const left = getRubberSideDisplayName(rubber, 'entry1')
  const right = getRubberSideDisplayName(rubber, 'entry2')

  return `${left} vs ${right}`
}

export function getTeamContextLabel(teamTie, rubber) {
  const entry1 = teamTie?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie?.entry2?.display_name ?? 'Equipo 2'
  const slot = rubber?.slot_order ?? '?'

  return `${entry1} vs ${entry2} · Partido ${slot}`
}

export function getRubberStatusLabel(rubber) {
  const status = rubber?.game?.status

  if (status === 'not_needed') {
    return 'No necesario'
  }

  if (!rubber?.lineup_complete) {
    return 'Definir jugadores'
  }

  if (status === 'finished') {
    return 'Finalizado'
  }

  if (status === 'in_progress') {
    return 'En juego'
  }

  return 'Listo'
}

export function getRubberStatusBadgeClasses(rubber) {
  const status = rubber?.game?.status

  if (status === 'not_needed') {
    return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
  }

  if (!rubber?.lineup_complete) {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
  }

  if (status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
  }

  if (status === 'in_progress') {
    return 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'
  }

  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
}

export function getRubberNotNeededMessage() {
  return 'El enfrentamiento ya estaba definido; este partido no fue necesario.'
}
