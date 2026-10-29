import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { buildGroupPhaseAlert, summarizeGroupPhaseBracketGate } from './buildGroupPhaseAlert.js'

const standing = (id, status = 'active') => ({ competition_entry_id: id, group_player_status: status })

describe('buildGroupPhaseAlert con bajas', () => {
  it('un grupo sin partidos y con 2 o más activos bloquea la llave', () => {
    const summary = buildGroupPhaseAlert({
      group: { id: 1 },
      standings: [standing(1), standing(2), standing(3, 'withdrawn')],
    })

    assert.equal(summary.blocksBracketGeneration, true)
  })

  it('un grupo reducido a un activo clasifica sin jugar y no bloquea', () => {
    const summary = buildGroupPhaseAlert({
      group: { id: 1 },
      standings: [standing(1), standing(2, 'withdrawn')],
    })

    assert.equal(summary.primaryLabel, 'Clasifica sin jugar')
    assert.equal(summary.blocksBracketGeneration, false)
    assert.equal(summary.isReady, true)
  })

  it('un grupo sin activos no bloquea la llave', () => {
    const summary = buildGroupPhaseAlert({
      group: { id: 1 },
      standings: [standing(1, 'withdrawn'), standing(2, 'disqualified')],
    })

    assert.equal(summary.primaryLabel, 'Sin participantes activos')
    assert.equal(summary.blocksBracketGeneration, false)
  })

  it('un grupo con un solo participante sin bajas sigue bloqueando', () => {
    const summary = buildGroupPhaseAlert({ group: { id: 1 }, standings: [standing(1)] })

    assert.equal(summary.primaryLabel, 'Sin todos contra todos')
    assert.equal(summary.blocksBracketGeneration, true)
  })

  it('los partidos pendientes siguen bloqueando aunque haya bajas', () => {
    const summary = buildGroupPhaseAlert({
      group: { id: 1 },
      standings: [standing(1), standing(2), standing(3, 'withdrawn')],
      games: [{ status: 'pending' }],
    })

    assert.equal(summary.primaryLabel, 'Partidos pendientes')
    assert.equal(summary.blocksBracketGeneration, true)
  })

  it('la llave queda habilitada si solo hay grupos listos o reducidos por bajas', () => {
    const ready = buildGroupPhaseAlert({
      group: { id: 1 },
      standings: [standing(1), standing(2)],
      games: [{ status: 'finished' }],
    })
    const reduced = buildGroupPhaseAlert({
      group: { id: 2 },
      standings: [standing(3), standing(4, 'withdrawn')],
    })

    assert.deepEqual(summarizeGroupPhaseBracketGate([ready, reduced]), {
      allGroupsReadyForBracket: true,
      blockMessage: null,
    })
  })
})
