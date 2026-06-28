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

export function buildBracketGenerationPreview({ qualifiedPerGroup = 2, groupCount = null } = {}) {
  const q = Number(qualifiedPerGroup) || 2
  const hasGroupCount = typeof groupCount === 'number'
  const introLines = []
  const statsLines = []
  const detailLines = []
  const warnings = []

  if (q === 2) {
    introLines.push(
      'Clasifican 2 jugadores por grupo.',
      'La llave se generará cruzando 1° de grupo contra 2° de otro grupo.',
    )
  } else if (q === 3) {
    introLines.push(
      'Clasifican 3 jugadores por grupo.',
      'Se generará una ronda clasificatoria: los 1° de grupo pasan directo y los 2°/3° juegan play-in.',
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

      if (q === 3) {
        const byesCount = groupCount
        const playInCount = groupCount

        statsLines.push(`Se generará un bracket de ${bracketSize} lugares:`)
        detailLines.push(`${byesCount} BYE${byesCount === 1 ? '' : 's'} para los 1° de grupo`)
        detailLines.push(`${playInCount} partido${playInCount === 1 ? '' : 's'} play-in entre 2° y 3°`)
      } else if (q === 2) {
        statsLines.push(`Se generará un bracket de ${bracketSize} lugares.`)

        const byesCount = bracketSize - totalQualified

        if (byesCount > 0) {
          detailLines.push(`${byesCount} BYE${byesCount === 1 ? '' : 's'} en la primera ronda`)
        }
      } else if (q === 1) {
        statsLines.push(`Se generará un bracket de ${bracketSize} lugares.`)

        const byesCount = bracketSize - totalQualified

        if (byesCount > 0) {
          detailLines.push(`${byesCount} BYE${byesCount === 1 ? '' : 's'} por completar el cuadro`)
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
      } else if (q === 3 && (groupCount < 4 || !isPowerOfTwo(groupCount))) {
        warnings.push(BRACKET_GENERATION_GROUP_COUNT_WARNING)
        warnings.push(`El draw con play-in requiere 4, 8 o 16 grupos (actual: ${groupCount}).`)
      }
    }
  }

  const badge =
    q === 2 ? BRACKET_GENERATION_BADGE_DIRECT : q === 3 ? BRACKET_GENERATION_BADGE_QUALIFYING : null

  return {
    title: BRACKET_GENERATION_PREVIEW_TITLE,
    badge,
    introLines,
    statsLines,
    detailLines,
    warnings,
    hasQualifyingRound: q === 3,
    hasByes: detailLines.some((line) => line.includes('BYE')),
  }
}
