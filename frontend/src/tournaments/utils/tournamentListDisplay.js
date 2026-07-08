const STATUS_LABELS = {
  draft: 'Draft',
  in_progress: 'En curso',
  finished: 'Finalizado',
}

export function getTournamentStatusLabel(status) {
  if (!status) {
    return '-'
  }

  return STATUS_LABELS[status] ?? status
}

export function getTournamentStatusBadgeClasses(status) {
  switch (status) {
    case 'in_progress':
      return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
    case 'finished':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    case 'draft':
    default:
      return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
  }
}
