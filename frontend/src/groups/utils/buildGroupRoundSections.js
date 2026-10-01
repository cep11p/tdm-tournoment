const UNASSIGNED_ROUND_LABEL = 'Sin ronda asignada'

const KIND_NOUNS = {
  games: ['partido', 'partidos'],
  team_ties: ['enfrentamiento', 'enfrentamientos'],
}

function countPhrase(count, singular, plural) {
  return `${count} ${count === 1 ? singular : plural}`
}

/**
 * El all-vs-all de grupo persiste group_round. La columna es nullable, así que
 * un game o team tie legado puede llegar sin ronda: no se muestra como "Ronda 0".
 */
function roundNumberOf(item) {
  const round = item?.group_round

  if (round == null || round === '') {
    return null
  }

  const numeric = Number(round)

  return Number.isFinite(numeric) ? numeric : null
}

function compareByGroupMatch(left, right) {
  const leftMatch = left?.group_match
  const rightMatch = right?.group_match
  const leftHasMatch = leftMatch != null && leftMatch !== ''
  const rightHasMatch = rightMatch != null && rightMatch !== ''

  if (leftHasMatch && rightHasMatch && Number(leftMatch) !== Number(rightMatch)) {
    return Number(leftMatch) - Number(rightMatch)
  }

  if (leftHasMatch && !rightHasMatch) {
    return -1
  }

  if (!leftHasMatch && rightHasMatch) {
    return 1
  }

  return (Number(left?.id) || 0) - (Number(right?.id) || 0)
}

function isCompleted(item) {
  return item?.status === 'finished'
}

function isPending(item, kind) {
  if (kind === 'team_ties') {
    return item?.status !== 'finished' && item?.status !== 'cancelled'
  }

  return item?.status === 'pending' || item?.status === 'in_progress'
}

function buildSection(roundNumber, label, roundItems, kind) {
  const [singular, plural] = KIND_NOUNS[kind]
  const pendingCount = roundItems.filter((item) => isPending(item, kind)).length
  const completedCount = roundItems.filter((item) => isCompleted(item)).length
  const totalCount = roundItems.length
  const isFinished = totalCount > 0 && completedCount === totalCount

  return {
    roundNumber,
    items: roundItems,
    totalCount,
    pendingCount,
    completedCount,
    isFinished,
    label,
    countLabel: countPhrase(totalCount, singular, plural),
    statusLabel: isFinished
      ? 'Finalizada'
      : countPhrase(pendingCount, 'pendiente', 'pendientes'),
    defaultOpen: false,
  }
}

function withDefaultOpen(sections) {
  let openedPending = false

  return sections.map((section) => {
    const defaultOpen = section.pendingCount > 0 && !openedPending

    if (defaultOpen) {
      openedPending = true
    }

    return {
      ...section,
      defaultOpen,
    }
  })
}

export function roundSectionKey(section) {
  if (section?.roundNumber == null) {
    return 'unassigned'
  }

  return String(section.roundNumber)
}

export function buildGroupRoundSections(items, options = {}) {
  const kind = options.kind === 'team_ties' ? 'team_ties' : 'games'
  const list = Array.isArray(items) ? items : []
  const eligible = kind === 'games' ? list.filter((item) => item?.is_bye !== true) : list.slice()

  const rounds = new Map()
  const unassigned = []

  for (const item of eligible) {
    const roundNumber = roundNumberOf(item)

    if (roundNumber == null) {
      unassigned.push(item)
      continue
    }

    if (!rounds.has(roundNumber)) {
      rounds.set(roundNumber, [])
    }

    rounds.get(roundNumber).push(item)
  }

  const sections = [...rounds.entries()]
    .sort(([leftRound], [rightRound]) => leftRound - rightRound)
    .map(([roundNumber, roundItems]) =>
      buildSection(
        roundNumber,
        `Ronda ${roundNumber}`,
        roundItems.slice().sort(compareByGroupMatch),
        kind,
      ),
    )

  if (unassigned.length > 0) {
    sections.push(
      buildSection(
        null,
        UNASSIGNED_ROUND_LABEL,
        unassigned.slice().sort(compareByGroupMatch),
        kind,
      ),
    )
  }

  return withDefaultOpen(sections)
}

export function mergeRoundOpenState(previousState, sections) {
  const previous = previousState && typeof previousState === 'object' ? previousState : {}
  let changed = false
  const next = { ...previous }

  for (const section of Array.isArray(sections) ? sections : []) {
    const key = roundSectionKey(section)

    if (!Object.prototype.hasOwnProperty.call(next, key)) {
      next[key] = Boolean(section?.defaultOpen)
      changed = true
    }
  }

  return changed ? next : previous
}
