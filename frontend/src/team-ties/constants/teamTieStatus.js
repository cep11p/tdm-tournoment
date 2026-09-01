const TEAM_TIE_STATUS_LABELS = {
  pending: 'Pendiente',
  ready: 'Listo',
  in_progress: 'En curso',
  finished: 'Finalizado',
  cancelled: 'Cancelado',
}

export const getTeamTieStatusLabel = (status) =>
  TEAM_TIE_STATUS_LABELS[status] ?? 'Pendiente'

export const getTeamTieStatusBadgeClasses = (status) => {
  if (status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200'
  }

  if (status === 'in_progress') {
    return 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200'
  }

  if (status === 'cancelled') {
    return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
  }

  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
}

export const isTeamTieFinished = (status) => status === 'finished'

export const isTeamTiePending = (status) =>
  status !== 'finished' && status !== 'cancelled'
