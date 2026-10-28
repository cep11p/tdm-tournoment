export const RATING_METHOD_DIFFERENCE_TABLE = 'difference_table'

const METHOD_LABELS = {
  [RATING_METHOD_DIFFERENCE_TABLE]: 'Tabla de diferencias',
}

export function ratingMethodLabel(method) {
  return METHOD_LABELS[method] ?? 'Método no definido'
}

export function ratingStatusLabel(active) {
  return active ? 'Activo' : 'Inactivo'
}

export function ratingStatusBadgeClasses(active) {
  if (active) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
  }

  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
}

export function ratingVersionStatusLabel(status) {
  if (status === 'draft') {
    return 'Borrador'
  }

  if (status === 'published') {
    return 'Publicada'
  }

  return 'Estado no definido'
}

export function ratingVersionStatusBadgeClasses(status) {
  if (status === 'published') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
  }

  if (status === 'draft') {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
  }

  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
}
