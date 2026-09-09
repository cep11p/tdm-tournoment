import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  groupConsecutiveMatchesByRound,
  isOfficialGroupSheetKind,
  printBestOfLabel,
  printOrientation,
  printSetColumnLabels,
  printSetColumns,
} from './groupPrint.js'

describe('isOfficialGroupSheetKind', () => {
  it('acepta g3, g4 y g5', () => {
    assert.equal(isOfficialGroupSheetKind('g3'), true)
    assert.equal(isOfficialGroupSheetKind('g4'), true)
    assert.equal(isOfficialGroupSheetKind('g5'), true)
  })

  it('trata generic y tamaños no oficiales como layout actual', () => {
    assert.equal(isOfficialGroupSheetKind('generic'), false)
    assert.equal(isOfficialGroupSheetKind('g2'), false)
    assert.equal(isOfficialGroupSheetKind('g6'), false)
    assert.equal(isOfficialGroupSheetKind(null), false)
    assert.equal(isOfficialGroupSheetKind(undefined), false)
  })
})

describe('printSetColumns / printSetColumnLabels', () => {
  it('genera S1..SN según best_of sin hardcodear 5', () => {
    assert.deepEqual(printSetColumns(3), [1, 2, 3])
    assert.deepEqual(printSetColumnLabels(3), ['S1', 'S2', 'S3'])
    assert.deepEqual(printSetColumnLabels(5), ['S1', 'S2', 'S3', 'S4', 'S5'])
    assert.deepEqual(printSetColumnLabels(7), ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7'])
  })
})

describe('printBestOfLabel', () => {
  it('usa el best_of del payload', () => {
    assert.equal(printBestOfLabel(3), 'Mejor de 3')
    assert.equal(printBestOfLabel(5), 'Mejor de 5')
    assert.equal(printBestOfLabel(7), 'Mejor de 7')
  })
})

describe('printOrientation', () => {
  it('mantiene portrait en BO3 y landscape en BO5/BO7', () => {
    assert.equal(printOrientation(3), 'portrait')
    assert.equal(printOrientation(5), 'landscape')
    assert.equal(printOrientation(7), 'landscape')
  })
})

describe('groupConsecutiveMatchesByRound', () => {
  it('no reordena y agrupa solo partidos consecutivos del mismo group_round', () => {
    const matches = [
      { order: 1, group_round: 1, group_match: 1, side1_number: 2, side2_number: 5 },
      { order: 2, group_round: 1, group_match: 2, side1_number: 3, side2_number: 4 },
      { order: 3, group_round: 2, group_match: 1, side1_number: 1, side2_number: 5 },
      { order: 4, group_round: 2, group_match: 2, side1_number: 2, side2_number: 3 },
    ]

    const rounds = groupConsecutiveMatchesByRound(matches)

    assert.equal(rounds.length, 2)
    assert.deepEqual(rounds[0].matches.map((match) => match.order), [1, 2])
    assert.deepEqual(rounds[1].matches.map((match) => match.order), [3, 4])
    assert.deepEqual(
      rounds.flatMap((round) => round.matches.map((match) => [match.side1_number, match.side2_number])),
      [[2, 5], [3, 4], [1, 5], [2, 3]],
    )
  })

  it('agrupa G4 en tres rondas de dos partidos en el orden del payload', () => {
    const rounds = groupConsecutiveMatchesByRound([
      { order: 1, group_round: 1, side1_number: 1, side2_number: 3 },
      { order: 2, group_round: 1, side1_number: 2, side2_number: 4 },
      { order: 3, group_round: 2, side1_number: 1, side2_number: 2 },
      { order: 4, group_round: 2, side1_number: 3, side2_number: 4 },
      { order: 5, group_round: 3, side1_number: 1, side2_number: 4 },
      { order: 6, group_round: 3, side1_number: 2, side2_number: 3 },
    ])

    assert.equal(rounds.length, 3)
    assert.deepEqual(
      rounds.map((round) => round.matches.map((match) => [match.side1_number, match.side2_number])),
      [
        [[1, 3], [2, 4]],
        [[1, 2], [3, 4]],
        [[1, 4], [2, 3]],
      ],
    )
  })

  it('agrupa G5 en cinco rondas sin alterar el orden 2-5 … 4-5', () => {
    const pairings = [[2, 5], [3, 4], [1, 5], [2, 3], [1, 4], [5, 3], [1, 3], [4, 2], [1, 2], [4, 5]]
    const matches = pairings.map((pair, index) => ({
      order: index + 1,
      group_round: Math.floor(index / 2) + 1,
      side1_number: pair[0],
      side2_number: pair[1],
    }))

    const rounds = groupConsecutiveMatchesByRound(matches)

    assert.equal(rounds.length, 5)
    assert.deepEqual(
      rounds.flatMap((round) => round.matches.map((match) => [match.side1_number, match.side2_number])),
      pairings,
    )
  })

  it('deja G3 como un partido por ronda', () => {
    const rounds = groupConsecutiveMatchesByRound([
      { order: 1, group_round: 1, side1_number: 1, side2_number: 3 },
      { order: 2, group_round: 2, side1_number: 1, side2_number: 2 },
      { order: 3, group_round: 3, side1_number: 2, side2_number: 3 },
    ])

    assert.equal(rounds.length, 3)
    assert.equal(rounds[0].matches.length, 1)
    assert.equal(rounds[1].matches.length, 1)
    assert.equal(rounds[2].matches.length, 1)
  })
})
