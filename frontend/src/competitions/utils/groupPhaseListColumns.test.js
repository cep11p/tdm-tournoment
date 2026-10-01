import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  countGroupParticipants,
  formatGroupScheduleProgress,
} from './groupPhaseListColumns.js'

describe('countGroupParticipants', () => {
  it('cuenta las participaciones del grupo', () => {
    assert.equal(
      countGroupParticipants({
        group_players: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }],
      }),
      4,
    )
  })

  it('devuelve 0 si no hay participaciones', () => {
    assert.equal(countGroupParticipants({ name: 'Grupo A' }), 0)
    assert.equal(countGroupParticipants(null), 0)
  })
})

describe('formatGroupScheduleProgress', () => {
  it('arma completados/total desde el meta de standings', () => {
    assert.equal(
      formatGroupScheduleProgress({
        completed_games_count: 0,
        total_games_count: 6,
      }),
      '0/6',
    )
  })

  it('usa el mismo meta para enfrentamientos de equipos', () => {
    assert.equal(
      formatGroupScheduleProgress({
        completed_games_count: 1,
        total_games_count: 3,
        finished_team_ties_count: 1,
        total_team_ties_count: 3,
      }),
      '1/3',
    )
  })

  it('no inventa un marcador si el meta no llegó', () => {
    assert.equal(formatGroupScheduleProgress(undefined), null)
    assert.equal(formatGroupScheduleProgress({}), null)
  })
})
