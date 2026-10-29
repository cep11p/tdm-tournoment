import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  canChangeGroupEntryStatus,
  fetchTournamentStatus,
} from './canChangeGroupEntryStatus.js'

const singles = { id: 1, type: 'singles' }
const doubles = { id: 2, type: 'doubles' }
const team = { id: 3, type: 'team' }
const activeEntry = { competition_entry_id: 10, status: 'active' }
const open = { tournamentStatus: 'in_progress' }

describe('canChangeGroupEntryStatus', () => {
  // Regresión: la UI ocultaba la acción apenas existía fixture, aunque el
  // backend cierra los partidos pendientes o en curso a favor del rival.
  // La regla ya no recibe partidos: en singles y dobles no dependen de ellos.
  it('permite la baja de un participante activo en singles y dobles', () => {
    assert.equal(canChangeGroupEntryStatus(activeEntry, { ...open, competition: singles }), true)
    assert.equal(canChangeGroupEntryStatus(activeEntry, { ...open, competition: doubles }), true)
  })

  it('trata como activo a un participante sin status', () => {
    assert.equal(
      canChangeGroupEntryStatus({ competition_entry_id: 10 }, { ...open, competition: singles }),
      true,
    )
  })

  it('rechaza participantes retirados o descalificados', () => {
    assert.equal(
      canChangeGroupEntryStatus({ ...activeEntry, status: 'withdrawn' }, { ...open, competition: singles }),
      false,
    )
    assert.equal(
      canChangeGroupEntryStatus({ ...activeEntry, status: 'disqualified' }, { ...open, competition: doubles }),
      false,
    )
  })

  it('rechaza cuando ya existe bracket', () => {
    for (const competition of [singles, doubles, team]) {
      assert.equal(
        canChangeGroupEntryStatus(activeEntry, { ...open, competition, hasBracket: true }),
        false,
      )
    }
  })

  it('en equipos permite la baja solo sin enfrentamientos generados', () => {
    assert.equal(canChangeGroupEntryStatus(activeEntry, { ...open, competition: team }), true)
    assert.equal(
      canChangeGroupEntryStatus(activeEntry, { ...open, competition: team, hasTeamTies: true }),
      false,
    )
  })

  it('rechaza cuando no hay participante', () => {
    assert.equal(canChangeGroupEntryStatus(null, { ...open, competition: singles }), false)
  })

  describe('estado administrativo del torneo', () => {
    it('rechaza mientras el estado del torneo no está disponible', () => {
      for (const competition of [singles, doubles, team]) {
        assert.equal(canChangeGroupEntryStatus(activeEntry, { competition }), false)
        assert.equal(
          canChangeGroupEntryStatus(activeEntry, { competition, tournamentStatus: null }),
          false,
        )
      }
    })

    it('rechaza cuando el torneo está finalizado', () => {
      for (const competition of [singles, doubles, team]) {
        assert.equal(
          canChangeGroupEntryStatus(activeEntry, { competition, tournamentStatus: 'finished' }),
          false,
        )
      }
    })

    it('permite cuando el torneo admite modificaciones', () => {
      for (const tournamentStatus of ['draft', 'in_progress']) {
        assert.equal(
          canChangeGroupEntryStatus(activeEntry, { competition: singles, tournamentStatus }),
          true,
        )
      }
    })

    it('rechaza estados de torneo desconocidos', () => {
      assert.equal(
        canChangeGroupEntryStatus(activeEntry, { competition: singles, tournamentStatus: 'archived' }),
        false,
      )
    })
  })
})

describe('fetchTournamentStatus', () => {
  it('devuelve Tournament.status cuando la consulta responde', async () => {
    const fetchTournament = async (id) => ({ id, status: 'in_progress' })

    assert.equal(await fetchTournamentStatus(fetchTournament, 7), 'in_progress')
    assert.equal(
      await fetchTournamentStatus(async () => ({ status: 'finished' }), 7),
      'finished',
    )
  })

  it('devuelve null si la consulta falla, y la baja queda deshabilitada', async () => {
    const failing = async () => {
      throw new Error('Network Error')
    }
    const tournamentStatus = await fetchTournamentStatus(failing, 7)

    assert.equal(tournamentStatus, null)
    assert.equal(
      canChangeGroupEntryStatus(activeEntry, { competition: singles, tournamentStatus }),
      false,
    )
  })

  it('devuelve null sin consultar cuando no hay torneo', async () => {
    let called = false
    const fetchTournament = async () => {
      called = true
      return { status: 'in_progress' }
    }

    assert.equal(await fetchTournamentStatus(fetchTournament, null), null)
    assert.equal(called, false)
  })

  it('devuelve null si la respuesta no trae status', async () => {
    assert.equal(await fetchTournamentStatus(async () => null, 7), null)
    assert.equal(await fetchTournamentStatus(async () => ({ id: 7 }), 7), null)
  })
})
