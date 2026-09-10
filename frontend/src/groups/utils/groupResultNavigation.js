const UNASSIGNED_ROUND_LABEL = 'Sin ronda asignada'

export function isNavigableGroupGame(game) {
  if (!game) {
    return false
  }

  if (game.is_bye === true) {
    return false
  }

  if (game.status === 'not_needed') {
    return false
  }

  return true
}

export function isLoadableGroupGame(game) {
  return isNavigableGroupGame(game) && (game.status === 'pending' || game.status === 'in_progress')
}

export function compareByGroupFixture(left, right) {
  const leftHasRound = left?.group_round != null
  const rightHasRound = right?.group_round != null

  if (leftHasRound !== rightHasRound) {
    return leftHasRound ? -1 : 1
  }

  if (leftHasRound && left.group_round !== right.group_round) {
    return left.group_round - right.group_round
  }

  const leftMatch = left?.group_match
  const rightMatch = right?.group_match

  if (leftMatch != null && rightMatch != null && leftMatch !== rightMatch) {
    return leftMatch - rightMatch
  }

  if (leftMatch != null && rightMatch == null) {
    return -1
  }

  if (leftMatch == null && rightMatch != null) {
    return 1
  }

  return (left?.id ?? 0) - (right?.id ?? 0)
}

export function getNavigableGamesForGroup(games, groupId) {
  const list = Array.isArray(games) ? games : []

  return list
    .filter((game) => Number(game.group_id) === Number(groupId))
    .filter(isNavigableGroupGame)
    .slice()
    .sort(compareByGroupFixture)
}

export function getNumberedRounds(games) {
  const rounds = new Set()

  for (const game of Array.isArray(games) ? games : []) {
    if (game?.group_round != null) {
      rounds.add(game.group_round)
    }
  }

  return [...rounds].sort((left, right) => left - right)
}

export function getGamesInRound(games, roundNumber) {
  const list = Array.isArray(games) ? games : []

  if (roundNumber == null) {
    return list.filter((game) => game.group_round == null)
  }

  return list.filter((game) => game.group_round === roundNumber)
}

export function findNavigableGame(games, gameId) {
  if (gameId == null) {
    return null
  }

  return (Array.isArray(games) ? games : []).find(
    (game) => Number(game.id) === Number(gameId),
  ) ?? null
}

export function getPreviousNavigableGame(games, gameId) {
  const list = Array.isArray(games) ? games : []
  const index = list.findIndex((game) => Number(game.id) === Number(gameId))

  if (index <= 0) {
    return null
  }

  return list[index - 1]
}

export function getNextNavigableGame(games, gameId) {
  const list = Array.isArray(games) ? games : []
  const index = list.findIndex((game) => Number(game.id) === Number(gameId))

  if (index < 0 || index >= list.length - 1) {
    return null
  }

  return list[index + 1]
}

export function getRoundContextLabel(game, navigableGames) {
  if (!game || game.group_round == null) {
    return UNASSIGNED_ROUND_LABEL
  }

  const total = getNumberedRounds(navigableGames).length

  return `Ronda ${game.group_round} de ${total}`
}

export function getMatchContextLabel(game, navigableGames) {
  if (!game) {
    return ''
  }

  const roundGames = getGamesInRound(navigableGames, game.group_round ?? null)
  const total = roundGames.length
  const matchNumber =
    game.group_match != null
      ? game.group_match
      : roundGames.findIndex((roundGame) => Number(roundGame.id) === Number(game.id)) + 1

  if (!matchNumber || total === 0) {
    return ''
  }

  return `Partido ${matchNumber} de ${total}`
}

export function findClosestRound(availableRounds, targetRound) {
  const rounds = Array.isArray(availableRounds) ? availableRounds : []

  if (rounds.length === 0) {
    return null
  }

  if (targetRound == null) {
    return rounds[0]
  }

  if (rounds.includes(targetRound)) {
    return targetRound
  }

  return rounds.reduce((closest, round) => {
    const closestDiff = Math.abs(closest - targetRound)
    const roundDiff = Math.abs(round - targetRound)

    if (roundDiff < closestDiff) {
      return round
    }

    if (roundDiff === closestDiff && round < closest) {
      return round
    }

    return closest
  })
}

export function selectGameWhenChangingGroup(allGames, targetGroupId, currentRound) {
  const games = getNavigableGamesForGroup(allGames, targetGroupId)

  if (games.length === 0) {
    return null
  }

  if (currentRound == null) {
    return games[0]
  }

  const round = findClosestRound(getNumberedRounds(games), currentRound)

  if (round == null) {
    return games[0]
  }

  const roundGames = getGamesInRound(games, round)

  return roundGames[0] ?? games[0]
}

export function findNextPendingGame(games, groupId, currentRound) {
  const loadableGames = getNavigableGamesForGroup(games, groupId).filter(isLoadableGroupGame)

  const sameRound = loadableGames.filter((game) =>
    currentRound == null ? game.group_round == null : game.group_round === currentRound,
  )

  if (sameRound.length > 0) {
    return sameRound[0]
  }

  if (currentRound == null) {
    return null
  }

  const laterNumbered = loadableGames.filter(
    (game) => game.group_round != null && game.group_round > currentRound,
  )

  if (laterNumbered.length > 0) {
    return laterNumbered[0]
  }

  const legacy = loadableGames.filter((game) => game.group_round == null)

  return legacy[0] ?? null
}

export function isGroupScheduleComplete(games, groupId) {
  const navigableGames = getNavigableGamesForGroup(games, groupId)

  return navigableGames.length > 0 && !navigableGames.some(isLoadableGroupGame)
}

export function sortCompetitionGroupsByName(groups) {
  return [...(Array.isArray(groups) ? groups : [])].sort((left, right) =>
    String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'es', {
      numeric: true,
      sensitivity: 'base',
    }),
  )
}

export function buildGroupResultNavigation({
  games,
  groupId,
  gameId,
  groups = [],
} = {}) {
  const navigableGames = getNavigableGamesForGroup(games, groupId)
  const currentGame = findNavigableGame(navigableGames, gameId)
  const previousGame = currentGame
    ? getPreviousNavigableGame(navigableGames, currentGame.id)
    : null
  const nextGame = currentGame
    ? getNextNavigableGame(navigableGames, currentGame.id)
    : null

  return {
    groups: sortCompetitionGroupsByName(groups),
    navigableGames,
    currentGame,
    previousGame,
    nextGame,
    canGoPrevious: Boolean(previousGame),
    canGoNext: Boolean(nextGame),
    roundLabel: getRoundContextLabel(currentGame, navigableGames),
    matchLabel: getMatchContextLabel(currentGame, navigableGames),
    selectedGroupId: groupId ?? null,
  }
}
