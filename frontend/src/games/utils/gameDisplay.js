const UNASSIGNED_SIDE_LABEL = 'Participante no asignado'

export function getGameSide(game, sideNumber) {
  if (!game) {
    return null
  }

  const sideKey = sideNumber === 1 ? 'side1' : 'side2'
  const side = game[sideKey]

  if (side?.competition_entry_id) {
    return side
  }

  if (sideNumber === 1 && game.player1?.id) {
    return {
      competition_entry_id: null,
      display_name: formatLegacyPlayerName(game.player1),
      members: [game.player1],
    }
  }

  if (sideNumber === 2 && game.player2?.id) {
    return {
      competition_entry_id: null,
      display_name: formatLegacyPlayerName(game.player2),
      members: [game.player2],
    }
  }

  return null
}

export function getGameSideDisplayName(game, sideNumber, options = {}) {
  const { unassigned = UNASSIGNED_SIDE_LABEL } = options
  const side = getGameSide(game, sideNumber)

  if (side?.display_name) {
    return side.display_name
  }

  return unassigned
}

export function getGameSideMembers(game, sideNumber) {
  const side = getGameSide(game, sideNumber)

  return Array.isArray(side?.members) ? side.members : []
}

export function isGameBye(game) {
  return game?.is_bye === true
}

export function getGameWinnerDisplayName(game, options = {}) {
  const { unassigned = '-' } = options

  if (isGameBye(game)) {
    return getGameSideDisplayName(game, 1, options)
  }

  if (!game?.winner_entry_id) {
    if (game?.winner_id) {
      if (game.winner_id === game.player1?.id) {
        return getGameSideDisplayName(game, 1, options)
      }

      if (game.winner_id === game.player2?.id) {
        return getGameSideDisplayName(game, 2, options)
      }

      return `Jugador #${game.winner_id}`
    }

    return unassigned
  }

  const winnerEntryId = Number(game.winner_entry_id)
  const side1 = getGameSide(game, 1)
  const side2 = getGameSide(game, 2)

  if (side1?.competition_entry_id && Number(side1.competition_entry_id) === winnerEntryId) {
    return side1.display_name || getGameSideDisplayName(game, 1, options)
  }

  if (side2?.competition_entry_id && Number(side2.competition_entry_id) === winnerEntryId) {
    return side2.display_name || getGameSideDisplayName(game, 2, options)
  }

  return unassigned
}

export function gameMatchupLabel(game, options = {}) {
  const side1Name = getGameSideDisplayName(game, 1, options)
  const side2Name = isGameBye(game)
    ? options.byeLabel ?? 'BYE'
    : getGameSideDisplayName(game, 2, options)

  return `${side1Name} vs ${side2Name}`
}

export function isGameSideWinner(game, sideNumber) {
  if (!game?.winner_entry_id) {
    const side = getGameSide(game, sideNumber)
    const player = sideNumber === 1 ? game.player1 : game.player2

    return Boolean(game?.winner_id && player?.id && game.winner_id === player.id)
  }

  const side = getGameSide(game, sideNumber)

  if (!side?.competition_entry_id) {
    return false
  }

  return Number(game.winner_entry_id) === Number(side.competition_entry_id)
}

function formatLegacyPlayerName(player) {
  if (!player?.id) {
    return UNASSIGNED_SIDE_LABEL
  }

  return `${player.first_name} ${player.last_name}`.trim()
}
