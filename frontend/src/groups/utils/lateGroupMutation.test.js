import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { suggestNextGroupName } from './suggestNextGroupName.js'
import {
  collectAssignedEntryIds,
  formatEntryDisplayName,
  listUnassignedEntries,
} from './unassignedCompetitionEntries.js'
import {
  GROUP_COMPOSITION_LOCKED_MESSAGE,
  canMutateGroupComposition,
  groupCompositionLockMessage,
} from './canMutateGroupComposition.js'

describe('suggestNextGroupName', () => {
  it('sugiere Grupo A si no hay grupos', () => {
    assert.equal(suggestNextGroupName([]), 'Grupo A')
  })

  it('sugiere Grupo D cuando existen A, B y C', () => {
    assert.equal(
      suggestNextGroupName(['Grupo A', 'Grupo B', 'Grupo C']),
      'Grupo D',
    )
  })

  it('llena el primer hueco alfabético', () => {
    assert.equal(
      suggestNextGroupName(['Grupo A', 'Grupo B', 'Grupo D']),
      'Grupo C',
    )
  })

  it('acepta objetos con name y compara sin distinguir mayúsculas', () => {
    assert.equal(
      suggestNextGroupName([{ name: 'grupo a' }, { name: 'Grupo B' }]),
      'Grupo C',
    )
  })

  it('después de A–Z sugiere Grupo 27', () => {
    const names = Array.from({ length: 26 }, (_, index) => `Grupo ${String.fromCharCode(65 + index)}`)

    assert.equal(suggestNextGroupName(names), 'Grupo 27')
  })

  it('evita un Grupo 27 ya usado', () => {
    const names = [
      ...Array.from({ length: 26 }, (_, index) => `Grupo ${String.fromCharCode(65 + index)}`),
      'Grupo 27',
    ]

    assert.equal(suggestNextGroupName(names), 'Grupo 28')
  })
})

describe('unassignedCompetitionEntries', () => {
  const groupA = {
    id: 1,
    name: 'Grupo A',
    group_players: [{ competition_entry_id: 10 }, { competition_entry_id: 11 }],
  }
  const groupB = {
    id: 2,
    name: 'Grupo B',
    group_players: [{ competition_entry_id: 20 }],
  }

  const registrations = [
    { id: 10, status: 'active', display_name: 'Carlos Pérez' },
    { id: 11, status: 'active', display_name: 'Juan Gómez' },
    { id: 20, status: 'active', display_name: 'Ana Ruiz' },
    { id: 30, status: 'active', display_name: 'Martín López' },
    { id: 40, status: 'withdrawn', display_name: 'Baja' },
    { id: 50, display_name: 'Sin status' },
  ]

  it('junta IDs asignados de todos los grupos', () => {
    assert.deepEqual(
      [...collectAssignedEntryIds([groupA, groupB])].sort((left, right) => left - right),
      [10, 11, 20],
    )
  })

  it('lista solo entries libres de la competencia', () => {
    const free = listUnassignedEntries(
      registrations,
      collectAssignedEntryIds([groupA, groupB]),
    )

    assert.deepEqual(
      free.map((entry) => entry.id),
      [30, 50],
    )
  })

  it('una entry de Grupo B no aparece como libre para Grupo A', () => {
    const free = listUnassignedEntries(registrations, collectAssignedEntryIds([groupA, groupB]))

    assert.equal(
      free.some((entry) => entry.id === 20),
      false,
    )
  })

  it('muestra el nombre singles desde display_name', () => {
    assert.equal(
      formatEntryDisplayName({ display_name: 'Carlos Pérez' }, { participantKind: 'player' }),
      'Carlos Pérez',
    )
  })

  it('muestra la dupla desde display_name', () => {
    assert.equal(
      formatEntryDisplayName(
        { display_name: 'Carlos Pérez / Juan Gómez' },
        { participantKind: 'pair' },
      ),
      'Carlos Pérez / Juan Gómez',
    )
  })

  it('arma la dupla con members si no hay display_name', () => {
    assert.equal(
      formatEntryDisplayName(
        {
          members: [
            { first_name: 'Carlos', last_name: 'Pérez' },
            { first_name: 'Juan', last_name: 'Gómez' },
          ],
        },
        { participantKind: 'pair' },
      ),
      'Carlos Pérez / Juan Gómez',
    )
  })
})

describe('canMutateGroupComposition', () => {
  const singlesGroups = {
    type: 'singles',
    has_group_stage: true,
    status_summary: { code: 'ready_for_bracket' },
  }

  it('permite crear grupo cuando no hay llave', () => {
    assert.equal(canMutateGroupComposition(singlesGroups, { hasBracket: false }), true)
  })

  it('oculta la mutación cuando ya hay llave', () => {
    assert.equal(canMutateGroupComposition(singlesGroups, { hasBracket: true }), false)
    assert.equal(
      groupCompositionLockMessage({ hasBracket: true }),
      GROUP_COMPOSITION_LOCKED_MESSAGE,
    )
  })

  it('no muestra controles tardíos en Team', () => {
    assert.equal(
      canMutateGroupComposition(
        { type: 'team', has_group_stage: true, status_summary: { code: 'group_stage_in_progress' } },
        { hasBracket: false },
      ),
      false,
    )
  })

  it('no aplica a eliminación directa', () => {
    assert.equal(
      canMutateGroupComposition(
        { type: 'singles', has_group_stage: false, format: 'knockout_direct' },
        { hasBracket: false },
      ),
      false,
    )
  })

  it('permite doubles sin llave', () => {
    assert.equal(
      canMutateGroupComposition(
        { type: 'doubles', has_group_stage: true, status_summary: { code: 'group_stage_pending' } },
        { hasBracket: false },
      ),
      true,
    )
  })
})
