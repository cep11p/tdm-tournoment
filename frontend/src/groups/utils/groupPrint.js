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
 * @returns {'portrait' | 'landscape'}
 */
export function printOrientation(bestOf) {
  return Number(bestOf) >= 5 ? 'landscape' : 'portrait'
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
