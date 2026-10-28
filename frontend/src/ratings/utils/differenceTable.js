export const DIFFERENCE_LABELS = {
  min: 'Desde',
  max: 'Hasta',
  favorite: 'Victoria del favorito',
  underdog: 'Victoria del jugador con menor rating',
}

export const PUBLISH_WARNING = 'Publicar deja estas reglas fijas. No vas a poder modificarlas.'

export const PUBLISH_CONFIRM_MESSAGE =
  'Publicar vuelve inmutables estas reglas. Después no se pueden modificar. ¿Publicar esta versión?'

export const INACTIVE_PUBLISH_MESSAGE =
  'No se puede publicar una versión mientras el sistema de rating está inactivo.'

export const SYMMETRIC_RULE_NOTE =
  'El perdedor pierde la misma cantidad de puntos que gana el vencedor.'

const ALLOWED_KEYS = ['min', 'max', 'favorite_win', 'underdog_win']

export function emptyDifferenceRow(isFirst = false) {
  return {
    min: isFirst ? 0 : '',
    max: '',
    favoriteWin: '',
    underdogWin: '',
    openEnded: true,
  }
}

export function rowsFromRules(rules) {
  if (!Array.isArray(rules)) {
    return []
  }

  return rules.map((range, index) => ({
    min: range?.min ?? '',
    max: range?.max ?? '',
    favoriteWin: range?.favorite_win ?? '',
    underdogWin: range?.underdog_win ?? '',
    openEnded: index === rules.length - 1 && (range?.max === null || range?.max === undefined),
  }))
}

function parseCell(value) {
  if (value === '' || value === null || value === undefined) {
    return null
  }

  if (typeof value === 'number') {
    return value
  }

  if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
    return Number(value.trim())
  }

  return value
}

export function rulesFromRows(rows) {
  return rows.map((row, index) => {
    const last = index === rows.length - 1

    return {
      min: parseCell(row.min),
      max: last && row.openEnded ? null : parseCell(row.max),
      favorite_win: parseCell(row.favoriteWin),
      underdog_win: parseCell(row.underdogWin),
    }
  })
}

function isNonNegativeInt(value) {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0
}

function pushError(errors, key, message) {
  errors[key] = [...(errors[key] ?? []), message]
}

export function differenceTableErrors(rules) {
  const errors = {}

  if (rules == null || (Array.isArray(rules) && rules.length === 0)) {
    pushError(errors, 'rules', 'La tabla de diferencias no puede estar vacía.')
    return errors
  }

  if (!Array.isArray(rules) || !isList(rules)) {
    pushError(errors, 'rules', 'La tabla de diferencias no es válida.')
    return errors
  }

  rules.forEach((range, index) => {
    if (!range || typeof range !== 'object' || Array.isArray(range)) {
      pushError(errors, `rules.${index}`, 'El rango no es válido.')
      return
    }

    const unknown = Object.keys(range).filter((key) => !ALLOWED_KEYS.includes(key))

    if (unknown.length > 0) {
      pushError(errors, `rules.${index}`, 'El rango incluye datos que no forman parte de la tabla.')
    }

    const isLast = index === rules.length - 1
    const min = range.min ?? null
    const hasMax = Object.prototype.hasOwnProperty.call(range, 'max')
    const max = hasMax ? range.max : false

    if (!isNonNegativeInt(min)) {
      pushError(errors, `rules.${index}.min`, 'El límite inferior debe ser un entero no negativo.')
    } else if (index === 0 && min !== 0) {
      pushError(errors, 'rules.0.min', 'El primer rango debe comenzar en 0.')
    }

    if (isLast) {
      if (max !== null) {
        pushError(errors, `rules.${index}.max`, 'El último rango no debe tener límite superior.')
      }
    } else if (max === null || max === false) {
      pushError(errors, `rules.${index}.max`, 'Solo el último rango puede no tener límite superior.')
    } else if (!isNonNegativeInt(max)) {
      pushError(errors, `rules.${index}.max`, 'El límite superior debe ser un entero no negativo.')
    } else if (Number.isInteger(min) && max < min) {
      pushError(errors, `rules.${index}.max`, 'El límite superior no puede ser menor que el inferior.')
    }

    if (!isNonNegativeInt(range.favorite_win)) {
      pushError(errors, `rules.${index}.favorite_win`, 'La victoria del favorito debe ser un entero no negativo.')
    }

    if (!isNonNegativeInt(range.underdog_win)) {
      pushError(
        errors,
        `rules.${index}.underdog_win`,
        'La victoria del jugador con menor rating debe ser un entero no negativo.',
      )
    }
  })

  for (let index = 1; index < rules.length; index += 1) {
    const previous = rules[index - 1]
    const current = rules[index]

    if (!previous || typeof previous !== 'object' || Array.isArray(previous)) {
      continue
    }

    if (!current || typeof current !== 'object' || Array.isArray(current)) {
      continue
    }

    if (!Number.isInteger(previous.max) || !Number.isInteger(current.min)) {
      continue
    }

    const expected = previous.max + 1

    if (current.min < expected) {
      pushError(errors, `rules.${index}.min`, 'Los rangos no pueden solaparse.')
    } else if (current.min > expected) {
      pushError(errors, `rules.${index}.min`, 'Los rangos deben ser contiguos, sin huecos.')
    }
  }

  return errors
}

export function rangeSpanLabel(range) {
  if (!range || typeof range !== 'object') {
    return '—'
  }

  if (range.max === null || range.max === undefined || range.max === '') {
    return `${range.min ?? '—'} o más`
  }

  return `${range.min}–${range.max}`
}

function isList(value) {
  return Array.isArray(value) && Object.keys(value).every((key, index) => Number(key) === index)
}
