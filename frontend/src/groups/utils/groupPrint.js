export const OFFICIAL_GROUP_SHEET_KINDS = ['g3', 'g4', 'g5']

/**
 * @param {string|null|undefined} sheetKind
 * @returns {boolean}
 */
export function isOfficialGroupSheetKind(sheetKind) {
  return OFFICIAL_GROUP_SHEET_KINDS.includes(sheetKind)
}

/**
 * @param {number|null|undefined} bestOf
 * @returns {number[]}
 */
export function printSetColumns(bestOf) {
  const count = Number(bestOf)

  if (!Number.isInteger(count) || count < 1) {
    return [1, 2, 3]
  }

  return Array.from({ length: count }, (_, index) => index + 1)
}

/**
 * @param {number|null|undefined} bestOf
 * @returns {string[]}
 */
export function printSetColumnLabels(bestOf) {
  return printSetColumns(bestOf).map((setNumber) => `S${setNumber}`)
}

/**
 * @param {number|null|undefined} bestOf
 * @returns {string}
 */
export function printBestOfLabel(bestOf) {
  const count = Number(bestOf)

  if (!Number.isInteger(count) || count < 1) {
    return '—'
  }

  return `Mejor de ${count}`
}

/**
 * Agrupa partidos consecutivos que comparten group_round.
 * No reordena: respeta el orden exacto del payload.
 *
 * @param {Array<{group_round?: number|null}|null|undefined>|null|undefined} matches
 * @returns {Array<{group_round: number|null, matches: object[]}>}
 */
export function groupConsecutiveMatchesByRound(matches) {
  const list = Array.isArray(matches) ? matches : []
  const groups = []

  for (const match of list) {
    if (match == null) {
      continue
    }

    const round = match.group_round ?? null
    const last = groups[groups.length - 1]

    if (last && last.group_round === round && round != null) {
      last.matches.push(match)
      continue
    }

    groups.push({
      group_round: round,
      matches: [match],
    })
  }

  return groups
}

/**
 * @param {number|null|undefined} bestOf
 * @returns {'portrait' | 'landscape'}
 */
export function printOrientation(bestOf) {
  return Number(bestOf) >= 5 ? 'landscape' : 'portrait'
}

/**
 * CSS @page cannot change orientation per page in a single print job.
 * V1 uses max(best_of) across sheets: all BO3 → portrait; any BO5/BO7 → landscape.
 *
 * @param {Array<{best_of?: number|null}|null|undefined>|null|undefined} sheets
 * @returns {number}
 */
export function printGlobalBestOf(sheets) {
  const values = (Array.isArray(sheets) ? sheets : [])
    .map((sheet) => Number(sheet?.best_of))
    .filter((value) => Number.isInteger(value) && value > 0)

  if (values.length === 0) {
    return 3
  }

  return Math.max(...values)
}

/**
 * @param {Array<{best_of?: number|null}|null|undefined>|null|undefined} sheets
 * @returns {'portrait' | 'landscape'}
 */
export function printOrientationFromSheets(sheets) {
  return printOrientation(printGlobalBestOf(sheets))
}

/**
 * @param {string|null|undefined} displayName
 * @returns {string}
 */
export function formatPrintDisplayName(displayName) {
  if (!displayName) {
    return '—'
  }

  return String(displayName).split(' / ').join(' /\n')
}

/**
 * @param {string|null|undefined} type
 * @returns {string}
 */
export function printCompetitionTypeLabel(type) {
  if (type === 'doubles') {
    return 'Dobles'
  }

  if (type === 'singles') {
    return 'Singles'
  }

  if (type === 'team') {
    return 'Equipos'
  }

  return type || '—'
}

/**
 * @param {{group_round?: number|null}|null|undefined} match
 * @returns {string}
 */
export function printRoundLabel(match) {
  if (match?.group_round == null) {
    return '—'
  }

  return `R${match.group_round}`
}

const PRINT_PAGE_STYLE_ID = 'group-print-page-style'

/**
 * @param {string} size
 */
export function applyPrintPageSize(size) {
  let styleEl = document.getElementById(PRINT_PAGE_STYLE_ID)

  if (!styleEl) {
    styleEl = document.createElement('style')
    styleEl.id = PRINT_PAGE_STYLE_ID
    document.head.appendChild(styleEl)
  }

  styleEl.textContent = `@media print { @page { size: ${size}; margin: 10mm; } }`
}

export function clearPrintPageSize() {
  document.getElementById(PRINT_PAGE_STYLE_ID)?.remove()
}
