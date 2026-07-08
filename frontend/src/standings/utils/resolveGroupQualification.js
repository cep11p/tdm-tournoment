export const QUALIFICATION_LABELS = {
  qualified: 'Clasifica',
  eliminated: 'Eliminado',
  not_eligible: 'No clasifica',
  provisional_qualified: 'Clasifica provisoriamente',
  provisional_eliminated: 'Fuera provisoriamente',
  tiebreak_pending: 'Desempate pendiente',
}

export function manualTieCrossesQualifierCutoff(standings, manualTiebreakGroups, qualifierCutoff) {
  if (qualifierCutoff <= 0) {
    return false
  }

  const positionByPlayerId = Object.fromEntries(
    standings.map((standing, index) => [standing.player_id, index]),
  )

  return manualTiebreakGroups.some((group) => {
    const positions = (group.player_ids ?? [])
      .map((playerId) => positionByPlayerId[playerId])
      .filter((position) => position !== undefined)

    if (positions.length === 0) {
      return false
    }

    const minPosition = Math.min(...positions)
    const maxPosition = Math.max(...positions)

    return minPosition < qualifierCutoff && maxPosition >= qualifierCutoff
  })
}

export function resolveGroupQualification({
  standing,
  position,
  qualifiedPerGroup,
  standingsAreProvisional,
  requiresManualTiebreak,
  manualTiebreakGroups,
  allStandings,
}) {
  if (standing.eligible_for_qualification === false) {
    return { kind: 'not_eligible' }
  }

  const tiebreakCrossesCutoff =
    requiresManualTiebreak &&
    manualTieCrossesQualifierCutoff(allStandings, manualTiebreakGroups, qualifiedPerGroup)

  if (tiebreakCrossesCutoff && standing.requires_manual_tiebreak) {
    return { kind: 'tiebreak_pending' }
  }

  const inCutoff = position <= qualifiedPerGroup

  if (standingsAreProvisional) {
    return { kind: inCutoff ? 'provisional_qualified' : 'provisional_eliminated' }
  }

  return { kind: inCutoff ? 'qualified' : 'eliminated' }
}

export function getQualificationLabel(kind) {
  return QUALIFICATION_LABELS[kind] ?? ''
}

export function getQualificationBadgeClasses(kind) {
  switch (kind) {
    case 'qualified':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    case 'eliminated':
      return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
    case 'not_eligible':
      return 'bg-slate-100 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-600'
    case 'provisional_qualified':
      return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600'
    case 'provisional_eliminated':
      return 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900/60 dark:text-slate-400 dark:ring-slate-700'
    case 'tiebreak_pending':
      return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
    default:
      return ''
  }
}

export function getQualificationIcon(kind) {
  switch (kind) {
    case 'qualified':
      return '✓'
    case 'eliminated':
      return '✗'
    case 'tiebreak_pending':
      return '!'
    default:
      return ''
  }
}

export function getQualificationRowClasses(kind, { isInactive = false } = {}) {
  if (isInactive) {
    return 'border-t border-slate-200 bg-slate-50/60 opacity-80 dark:border-slate-700 dark:bg-slate-800/40'
  }

  switch (kind) {
    case 'qualified':
      return 'border-t border-slate-200 bg-emerald-50/40 dark:border-slate-700 dark:bg-emerald-950/20'
    case 'eliminated':
      return 'border-t border-slate-200 bg-red-50/30 dark:border-slate-700 dark:bg-red-950/10'
    case 'not_eligible':
      return 'border-t border-slate-200 bg-slate-50/60 opacity-80 dark:border-slate-700 dark:bg-slate-800/40'
    case 'provisional_qualified':
      return 'border-t border-slate-200 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-900/30'
    case 'provisional_eliminated':
      return 'border-t border-slate-200 dark:border-slate-700'
    case 'tiebreak_pending':
      return 'border-t border-slate-200 bg-amber-50/40 dark:border-slate-700 dark:bg-amber-950/20'
    default:
      return 'border-t border-slate-200 dark:border-slate-700'
  }
}

export function getQualificationCardClasses(kind, { isInactive = false } = {}) {
  if (isInactive) {
    return 'border-slate-200 bg-slate-50/60 opacity-80 dark:border-slate-700 dark:bg-slate-800/40'
  }

  switch (kind) {
    case 'qualified':
      return 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900 dark:bg-emerald-950/20'
    case 'eliminated':
      return 'border-red-200 bg-red-50/30 dark:border-red-900 dark:bg-red-950/10'
    case 'tiebreak_pending':
      return 'border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/20'
    case 'provisional_qualified':
      return 'border-slate-200 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-900/30'
    default:
      return 'border-slate-200 dark:border-slate-700'
  }
}
