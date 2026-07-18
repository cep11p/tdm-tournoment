export const TOURNAMENT_STATUS_OPTIONS = [
  { value: 'draft', label: 'Borrador' },
  { value: 'in_progress', label: 'En curso' },
  { value: 'finished', label: 'Finalizado' },
]

export const TOURNAMENT_STATUS_EDIT_OPTIONS = TOURNAMENT_STATUS_OPTIONS.filter(
  (option) => option.value !== 'finished',
)

const STATUS_LABELS = Object.fromEntries(
  TOURNAMENT_STATUS_OPTIONS.map(({ value, label }) => [value, label]),
)

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
