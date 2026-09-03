const MISSING_LINEUP_LABEL = 'Por definir'

const RUBBER_STATUS_LABELS = {
  pending: 'Pendiente',
  in_progress: 'En juego',
  not_needed: 'No necesario',
}

export function printRubberSideLabel(side) {
  const players = Array.isArray(side?.players) ? side.players : []
  const names = players
    .map((player) => String(player?.name ?? '').trim())
    .filter(Boolean)

  if (names.length === 0) {
    return MISSING_LINEUP_LABEL
  }

  return names.join(' / ')
}

export function printRubberStatusLabel(rubber) {
  if (rubber?.status === 'not_needed') {
    return RUBBER_STATUS_LABELS.not_needed
  }

  if (rubber?.status === 'finished' && rubber?.official === false) {
    return 'No oficial'
  }

  if (rubber?.status === 'finished' && rubber?.official === true) {
    return ''
  }

  return RUBBER_STATUS_LABELS[rubber?.status] ?? RUBBER_STATUS_LABELS.pending
}

export function printShouldShowScore(sheet) {
  if (sheet?.team_tie?.is_bye) {
    return false
  }

  const status = sheet?.team_tie?.status
  const side1 = Number(sheet?.score?.side1) || 0
  const side2 = Number(sheet?.score?.side2) || 0

  return status === 'in_progress' || status === 'finished' || side1 > 0 || side2 > 0
}
