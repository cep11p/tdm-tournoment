import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'

export function rankingTypeLabel(type) {
  return getCompetitionTypeLabel(type)
}

export function rankingCategoryLabel(category) {
  if (!category) {
    return 'Todas las categorías'
  }

  return category.name || 'Todas las categorías'
}

export function rankingStatusLabel(active) {
  return active ? 'Activo' : 'Inactivo'
}

export function rankingStatusBadgeClasses(active) {
  if (active) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
  }

  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
}

export function formatRankingPosition(position) {
  if (position == null || position === '') {
    return '-'
  }

  return `${position}°`
}

export function formatRankingDate(value) {
  if (!value) {
    return null
  }

  const datePart = String(value).slice(0, 10)
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(datePart)

  if (match) {
    const [, year, month, day] = match
    const date = new Date(Number(year), Number(month) - 1, Number(day))

    return formatDateEsAr(date)
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return null
  }

  return formatDateEsAr(date)
}

export function formatRankingValidity(startsAt, endsAt) {
  const start = formatRankingDate(startsAt)
  const end = formatRankingDate(endsAt)

  if (!start && !end) {
    return null
  }

  if (start && end) {
    return `Vigencia: ${start} – ${end}`
  }

  return `Vigencia: ${start || end}`
}

export function rankingPositionBadgeClasses(position) {
  if (position === 1) {
    return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
  }

  if (position === 2) {
    return 'bg-slate-200 text-slate-800 ring-1 ring-slate-300 dark:bg-slate-600 dark:text-slate-100 dark:ring-slate-500'
  }

  if (position === 3) {
    return 'bg-orange-100 text-orange-900 ring-1 ring-orange-200 dark:bg-orange-900/50 dark:text-orange-200 dark:ring-orange-800'
  }

  return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
}

function formatDateEsAr(date) {
  return new Intl.DateTimeFormat('es-AR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}
