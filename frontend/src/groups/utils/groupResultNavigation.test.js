import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  buildGroupResultNavigation,
  findClosestRound,
  findNextPendingGame,
  getMatchContextLabel,
  getNavigableGamesForGroup,
  getNextNavigableGame,
  getPreviousNavigableGame,
  getRoundContextLabel,
  isGroupScheduleComplete,
  selectGameWhenChangingGroup,
  sortCompetitionGroupsByName,
} from './groupResultNavigation.js'

const fixtureKey = (game) => [game.group_round, game.group_match]

const buildGroupGames = ({
  groupId,
  rounds,
  matchesPerRound,
  startId = 1,
} = {}) => {
  const games = []
  let id = startId

  for (let round = 1; round <= rounds; round += 1) {
    for (let match = 1; match <= matchesPerRound; match += 1) {
      games.push({
        id,
        group_id: groupId,
        group_round: round,
        group_match: match,
        status: 'pending',
        is_bye: false,
      })
      id += 1
    }
  }

  return games
}

const shuffle = (games) =>
  [...games].sort((left, right) => right.id - left.id)

const walkNext = (games, startGame) => {
  const path = [startGame]
  let current = startGame

  while (true) {
    const next = getNextNavigableGame(games, current.id)

    if (!next) {
      return path
    }

    path.push(next)
    current = next
  }
}

describe('getNavigableGamesForGroup', () => {
  it('ordena G3 como 3 rondas de 1 partido', () => {
    const games = shuffle(buildGroupGames({ groupId: 3, rounds: 3, matchesPerRound: 1 }))
    const ordered = getNavigableGamesForGroup(games, 3)

    assert.deepEqual(
      ordered.map(fixtureKey),
      [[1, 1], [2, 1], [3, 1]],
    )
  })

  it('ordena G4 como R1/P1 … R3/P2 aunque el array llegue desordenado', () => {
    const games = shuffle(buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }))
    const ordered = getNavigableGamesForGroup(games, 4)

    assert.deepEqual(
      ordered.map(fixtureKey),
      [
        [1, 1],
        [1, 2],
        [2, 1],
        [2, 2],
        [3, 1],
        [3, 2],
      ],
    )
  })

  it('ordena G5 como 5 rondas de 2 partidos', () => {
    const games = shuffle(buildGroupGames({ groupId: 5, rounds: 5, matchesPerRound: 2 }))
    const ordered = getNavigableGamesForGroup(games, 5)

    assert.equal(ordered.length, 10)
    assert.deepEqual(ordered.map(fixtureKey), [
      [1, 1], [1, 2],
      [2, 1], [2, 2],
      [3, 1], [3, 2],
      [4, 1], [4, 2],
      [5, 1], [5, 2],
    ])
  })

  it('ordena G6 como 5 rondas de 3 partidos', () => {
    const games = shuffle(buildGroupGames({ groupId: 6, rounds: 5, matchesPerRound: 3 }))
    const ordered = getNavigableGamesForGroup(games, 6)

    assert.equal(ordered.length, 15)
    assert.deepEqual(ordered[0] && fixtureKey(ordered[0]), [1, 1])
    assert.deepEqual(fixtureKey(ordered[2]), [1, 3])
    assert.deepEqual(fixtureKey(ordered[3]), [2, 1])
    assert.deepEqual(fixtureKey(ordered[14]), [5, 3])
  })

  it('excluye BYE y not_needed, pero incluye finished', () => {
    const games = [
      { id: 1, group_id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: false },
      { id: 2, group_id: 1, group_round: 1, group_match: 2, status: 'pending', is_bye: true },
      { id: 3, group_id: 1, group_round: 2, group_match: 1, status: 'not_needed', is_bye: false },
      { id: 4, group_id: 1, group_round: 2, group_match: 2, status: 'in_progress', is_bye: false },
    ]

    const ordered = getNavigableGamesForGroup(games, 1)

    assert.deepEqual(ordered.map((game) => game.id), [1, 4])
  })

  it('deja group_round null al final ordenado por id', () => {
    const games = [
      { id: 30, group_id: 1, group_round: null, group_match: null, status: 'pending', is_bye: false },
      { id: 10, group_id: 1, group_round: 1, group_match: 1, status: 'pending', is_bye: false },
      { id: 20, group_id: 1, group_round: null, group_match: null, status: 'finished', is_bye: false },
    ]

    const ordered = getNavigableGamesForGroup(games, 1)

    assert.deepEqual(ordered.map((game) => game.id), [10, 20, 30])
  })

  it('ignora partidos de otros grupos', () => {
    const games = [
      ...buildGroupGames({ groupId: 1, rounds: 1, matchesPerRound: 1, startId: 1 }),
      ...buildGroupGames({ groupId: 2, rounds: 1, matchesPerRound: 1, startId: 50 }),
    ]

    assert.deepEqual(getNavigableGamesForGroup(games, 2).map((game) => game.id), [50])
  })
})

describe('previous / next', () => {
  it('en G3 recorre ronda a ronda y etiqueta Partido 1 de 1', () => {
    const games = getNavigableGamesForGroup(
      buildGroupGames({ groupId: 3, rounds: 3, matchesPerRound: 1 }),
      3,
    )
    const first = games[0]
    const second = games[1]
    const last = games[2]

    assert.equal(getPreviousNavigableGame(games, first.id), null)
    assert.equal(getNextNavigableGame(games, last.id), null)
    assert.equal(getNextNavigableGame(games, first.id).id, second.id)
    assert.equal(getPreviousNavigableGame(games, last.id).id, second.id)
    assert.equal(getRoundContextLabel(first, games), 'Ronda 1 de 3')
    assert.equal(getRoundContextLabel(last, games), 'Ronda 3 de 3')
    assert.equal(getMatchContextLabel(first, games), 'Partido 1 de 1')
    assert.equal(getMatchContextLabel(second, games), 'Partido 1 de 1')
  })

  it('en G4 recorre R1/P1 → R1/P2 → R2/P1 y no el índice del array original', () => {
    const source = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 })
    const games = getNavigableGamesForGroup(shuffle(source), 4)
    const path = walkNext(games, games[0]).map(fixtureKey)

    assert.deepEqual(path, [
      [1, 1],
      [1, 2],
      [2, 1],
      [2, 2],
      [3, 1],
      [3, 2],
    ])

    const round2Match1 = games.find((game) => game.group_round === 2 && game.group_match === 1)
    assert.equal(getRoundContextLabel(round2Match1, games), 'Ronda 2 de 3')
    assert.equal(getMatchContextLabel(round2Match1, games), 'Partido 1 de 2')
    assert.equal(
      getMatchContextLabel(
        games.find((game) => game.group_round === 2 && game.group_match === 2),
        games,
      ),
      'Partido 2 de 2',
    )
  })

  it('en G5 y G6 el primer partido no tiene previous y el último no tiene next', () => {
    const g5 = getNavigableGamesForGroup(
      buildGroupGames({ groupId: 5, rounds: 5, matchesPerRound: 2 }),
      5,
    )
    const g6 = getNavigableGamesForGroup(
      buildGroupGames({ groupId: 6, rounds: 5, matchesPerRound: 3 }),
      6,
    )

    assert.equal(g5.length, 10)
    assert.equal(g6.length, 15)
    assert.equal(getPreviousNavigableGame(g5, g5[0].id), null)
    assert.equal(getNextNavigableGame(g5, g5[g5.length - 1].id), null)
    assert.equal(getPreviousNavigableGame(g6, g6[0].id), null)
    assert.equal(getNextNavigableGame(g6, g6[g6.length - 1].id), null)
    assert.equal(walkNext(g5, g5[0]).length, 10)
    assert.equal(walkNext(g6, g6[0]).length, 15)
  })

  it('permite navegar un partido finished', () => {
    const games = getNavigableGamesForGroup(
      [
        { id: 1, group_id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: false },
        { id: 2, group_id: 1, group_round: 1, group_match: 2, status: 'pending', is_bye: false },
      ],
      1,
    )

    assert.equal(getNextNavigableGame(games, 1).id, 2)
    assert.equal(getPreviousNavigableGame(games, 2).status, 'finished')
  })
})

describe('labels legacy', () => {
  it('usa Sin ronda asignada cuando group_round es null', () => {
    const games = getNavigableGamesForGroup(
      [
        { id: 1, group_id: 1, group_round: null, group_match: null, status: 'pending', is_bye: false },
        { id: 2, group_id: 1, group_round: null, group_match: null, status: 'pending', is_bye: false },
      ],
      1,
    )

    assert.equal(getRoundContextLabel(games[0], games), 'Sin ronda asignada')
    assert.equal(getMatchContextLabel(games[1], games), 'Partido 2 de 2')
  })
})

describe('cambio de grupo', () => {
  it('conserva la misma ronda y abre el primer partido de esa ronda', () => {
    const allGames = [
      ...buildGroupGames({ groupId: 1, rounds: 3, matchesPerRound: 2, startId: 1 }),
      ...buildGroupGames({ groupId: 2, rounds: 3, matchesPerRound: 2, startId: 100 }),
    ]
    const selected = selectGameWhenChangingGroup(shuffle(allGames), 2, 2)

    assert.equal(selected.group_id, 2)
    assert.equal(selected.group_round, 2)
    assert.equal(selected.group_match, 1)
  })

  it('cae a la ronda más cercana si el destino no tiene esa ronda', () => {
    const allGames = [
      ...buildGroupGames({ groupId: 1, rounds: 5, matchesPerRound: 2, startId: 1 }),
      ...buildGroupGames({ groupId: 2, rounds: 3, matchesPerRound: 2, startId: 100 }),
    ]
    const selected = selectGameWhenChangingGroup(allGames, 2, 5)

    assert.equal(selected.group_round, 3)
    assert.equal(selected.group_match, 1)
    assert.equal(findClosestRound([1, 2, 3], 5), 3)
  })

  it('devuelve null si el grupo destino no tiene partidos navegables', () => {
    const allGames = buildGroupGames({ groupId: 1, rounds: 1, matchesPerRound: 1 })

    assert.equal(selectGameWhenChangingGroup(allGames, 99, 1), null)
  })
})

describe('sortCompetitionGroupsByName', () => {
  it('ordena por nombre y no por id', () => {
    const groups = [
      { id: 40, name: 'Grupo D' },
      { id: 10, name: 'Grupo B' },
      { id: 30, name: 'Grupo A' },
      { id: 20, name: 'Grupo C' },
    ]

    assert.deepEqual(
      sortCompetitionGroupsByName(groups).map((group) => group.name),
      ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D'],
    )
  })
})

describe('buildGroupResultNavigation', () => {
  it('arma el cursor del partido actual sin usar el orden de llegada', () => {
    const games = shuffle(buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }))
    const current = games.find((game) => game.group_round === 1 && game.group_match === 2)
    const navigation = buildGroupResultNavigation({
      games,
      groupId: 4,
      gameId: current.id,
      groups: [{ id: 4, name: 'Grupo B' }, { id: 1, name: 'Grupo A' }],
    })

    assert.equal(navigation.roundLabel, 'Ronda 1 de 3')
    assert.equal(navigation.matchLabel, 'Partido 2 de 2')
    assert.deepEqual(fixtureKey(navigation.previousGame), [1, 1])
    assert.deepEqual(fixtureKey(navigation.nextGame), [2, 1])
    assert.deepEqual(navigation.groups.map((group) => group.name), ['Grupo A', 'Grupo B'])
  })
})

describe('findNextPendingGame', () => {
  const mark = (game, status) => ({ ...game, status })

  it('abre el pendiente restante de la misma ronda', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) =>
      game.group_round === 1 && game.group_match === 1 ? mark(game, 'finished') : game,
    )

    const next = findNextPendingGame(games, 4, 1)

    assert.deepEqual(fixtureKey(next), [1, 2])
  })

  it('prioriza un pendiente anterior de la misma ronda', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) =>
      game.group_round === 1 && game.group_match === 2 ? mark(game, 'finished') : game,
    )

    const next = findNextPendingGame(games, 4, 1)

    assert.deepEqual(fixtureKey(next), [1, 1])
  })

  it('pasa a la siguiente ronda si la actual quedó completa', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) =>
      game.group_round === 1 ? mark(game, 'finished') : game,
    )

    const next = findNextPendingGame(games, 4, 1)

    assert.deepEqual(fixtureKey(next), [2, 1])
  })

  it('salta finished de la ronda siguiente', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) => {
      if (game.group_round === 1) {
        return mark(game, 'finished')
      }

      if (game.group_round === 2 && game.group_match === 1) {
        return mark(game, 'finished')
      }

      return game
    })

    const next = findNextPendingGame(games, 4, 1)

    assert.deepEqual(fixtureKey(next), [2, 2])
  })

  it('trata in_progress como candidato operativo', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) => {
      if (game.group_round === 1 && game.group_match === 1) {
        return mark(game, 'finished')
      }

      if (game.group_round === 1 && game.group_match === 2) {
        return mark(game, 'in_progress')
      }

      return game
    })

    const next = findNextPendingGame(games, 4, 1)

    assert.equal(next.status, 'in_progress')
    assert.deepEqual(fixtureKey(next), [1, 2])
  })

  it('devuelve null si el grupo está completo', () => {
    const games = buildGroupGames({ groupId: 4, rounds: 3, matchesPerRound: 2 }).map((game) =>
      mark(game, 'finished'),
    )

    assert.equal(findNextPendingGame(games, 4, 3), null)
    assert.equal(isGroupScheduleComplete(games, 4), true)
  })

  it('no elige BYE ni not_needed', () => {
    const games = [
      { id: 1, group_id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: false },
      { id: 2, group_id: 1, group_round: 2, group_match: 1, status: 'pending', is_bye: true },
      { id: 3, group_id: 1, group_round: 2, group_match: 2, status: 'not_needed', is_bye: false },
      { id: 4, group_id: 1, group_round: 3, group_match: 1, status: 'pending', is_bye: false },
    ]

    assert.equal(findNextPendingGame(games, 1, 1).id, 4)
  })

  it('desde una ronda numerada completa cae a group_round null, y no vuelve atrás', () => {
    const withLegacyPending = [
      { id: 1, group_id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: false },
      { id: 2, group_id: 1, group_round: null, group_match: null, status: 'pending', is_bye: false },
    ]
    const fromLegacy = [
      { id: 1, group_id: 1, group_round: 1, group_match: 1, status: 'pending', is_bye: false },
      { id: 2, group_id: 1, group_round: null, group_match: null, status: 'finished', is_bye: false },
    ]

    assert.equal(findNextPendingGame(withLegacyPending, 1, 1).id, 2)
    assert.equal(findNextPendingGame(fromLegacy, 1, null), null)
  })
})
