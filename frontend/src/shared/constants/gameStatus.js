export const GAME_STATUS_LABELS = {
  pending: 'Pendiente',
  in_progress: 'En curso',
  finished: 'Finalizado',
}

export function getGameStatusLabel(status) {
  if (!status) {
    return 'Sin estado'
  }

  return GAME_STATUS_LABELS[status] ?? status
}

export function getGameStatusBadgeClasses(status) {
  if (status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  if (status === 'in_progress') {
    return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
  }

  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
}

export function getGameListStatusBadge(status) {
  if (status === 'finished') {
    return '✓ Finalizado'
  }

  if (status === 'pending') {
    return '⏳ Pendiente'
  }

  if (status === 'in_progress') {
    return 'En curso'
  }

  return getGameStatusLabel(status)
}
