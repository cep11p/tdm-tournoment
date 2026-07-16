export const BRACKET_GENERATION_PREVIEW_TITLE = 'Generación de llave eliminatoria'
export const BRACKET_GENERATION_BADGE_DIRECT = 'Cruce directo'
export const BRACKET_GENERATION_BADGE_QUALIFYING = 'Ronda clasificatoria'
export const BRACKET_GENERATION_GROUP_COUNT_WARNING =
  'Este formato requiere una cantidad de grupos compatible para generar la llave automáticamente.'

const isPowerOfTwo = (value) => value > 0 && (value & (value - 1)) === 0

const nextPowerOfTwo = (count) => {
  if (count <= 1) {
    return 2
  }

  let power = 1

  while (power < count) {
    power <<= 1
  }

  return power
}

const canUsePlayInDraw = (groupCount) => groupCount >= 4 && isPowerOfTwo(groupCount)

const formatByeCountLabel = (count, suffix = '') => {
  const base =
    count === 1 ? `${count} pase directo (BYE)` : `${count} pases directos (BYE)`

  return suffix ? `${base} ${suffix}` : base
}

export function buildBracketGenerationPreview({ qualifiedPerGroup = 2, groupCount = null } = {}) {
  const q = Number(qualifiedPerGroup) || 2
  const hasGroupCount = typeof groupCount === 'number'
  const introLines = []
  const statsLines = []
  const detailLines = []
  const warnings = []

  const usesPlayIn = q === 3 && hasGroupCount && groupCount > 0 && canUsePlayInDraw(groupCount)

  if (q === 2) {
    introLines.push(
      'Clasifican 2 jugadores por grupo.',
      'La llave se generará cruzando 1° de grupo contra 2° de otro grupo.',
    )
  } else if (q === 3 && usesPlayIn) {
    introLines.push(
      'Clasifican 3 jugadores por grupo.',
      'Se generará una ronda clasificatoria: los 1° de grupo pasan directo y los 2°/3° juegan la ronda preliminar.',
    )
  } else if (q === 3) {
    introLines.push(
      'Clasifican 3 jugadores por grupo.',
      'La llave se generará en formato directo con los clasificados de cada grupo.',
    )
  } else if (q === 1) {
    introLines.push(
      'Clasifica 1 jugador por grupo.',
      'La llave se armará por ranking global de la fase de grupos.',
    )
  } else {
    introLines.push(`Clasifican ${q} jugadores por grupo.`)
  }

  if (hasGroupCount) {
    if (groupCount === 0) {
      warnings.push('La competencia todavía no tiene grupos.')
    } else {
      const totalQualified = groupCount * q
      const bracketSize = nextPowerOfTwo(totalQualified)

      statsLines.push(
        `${groupCount} grupo${groupCount === 1 ? '' : 's'} × ${q} clasificado${q === 1 ? '' : 's'} = ${totalQualified} jugador${totalQualified === 1 ? '' : 'es'}.`,
      )

      if (usesPlayIn) {
        const byesCount = groupCount
        const playInCount = groupCount

        statsLines.push(`Se generará una llave de ${bracketSize} lugares:`)
        detailLines.push(formatByeCountLabel(byesCount, 'para los 1° de grupo'))
        detailLines.push(
          `${playInCount} partido${playInCount === 1 ? '' : 's'} de ronda preliminar entre 2° y 3°`,
        )
      } else if (q === 2 || q === 3 || q === 1) {
        statsLines.push(`Se generará una llave de ${bracketSize} posiciones.`)

        const byesCount = bracketSize - totalQualified

        if (byesCount > 0) {
          detailLines.push(formatByeCountLabel(byesCount, 'en la primera ronda'))
        }
      }

      if (q === 2 && !isPowerOfTwo(groupCount)) {
        warnings.push(BRACKET_GENERATION_GROUP_COUNT_WARNING)
        warnings.push(
          `El cuadro eliminatorio requiere una cantidad de grupos potencia de 2 (actual: ${groupCount}).`,
        )
      } else if (q === 2 && !isPowerOfTwo(totalQualified)) {
        warnings.push(BRACKET_GENERATION_GROUP_COUNT_WARNING)
        warnings.push(
          `El total de clasificados debe ser potencia de 2 (actual: ${totalQualified}).`,
        )
      }
    }
  }

  const badge = usesPlayIn
    ? BRACKET_GENERATION_BADGE_QUALIFYING
    : q === 2 || q === 3
      ? BRACKET_GENERATION_BADGE_DIRECT
      : null

  return {
    title: BRACKET_GENERATION_PREVIEW_TITLE,
    badge,
    introLines,
    statsLines,
    detailLines,
    warnings,
    hasQualifyingRound: usesPlayIn,
    hasByes: detailLines.some((line) => line.includes('Pase directo') || line.includes('pases directos')),
  }
}
