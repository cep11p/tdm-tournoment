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
import {
  canEditGroupEntry,
  entryHasStartedOrFinishedGames,
  groupEntryLockMessage,
} from './canEditGroupEntry.js'
import { listMoveTargetGroups } from './listMoveTargetGroups.js'
import {
  buildMoveEntryRequest,
  buildRemoveEntryRequest,
} from './groupCompositionApi.js'

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

  it('no muestra controles cuando la competencia está completed', () => {
    assert.equal(
      canMutateGroupComposition(
        { type: 'singles', has_group_stage: true, status_summary: { code: 'completed' } },
        { hasBracket: false },
      ),
      false,
    )
  })
})

describe('canEditGroupEntry', () => {
  const groupId = 4

  const game = ({
    entryId = 10,
    otherEntryId = 11,
    status = 'pending',
    extra = {},
  } = {}) => ({
    id: extra.id ?? 1,
    group_id: extra.group_id ?? groupId,
    status,
    side1: { competition_entry_id: extra.side1 ?? entryId },
    side2: { competition_entry_id: extra.side2 ?? otherEntryId },
    ...extra,
  })

  it('permite editar una entry sin Games', () => {
    assert.equal(canEditGroupEntry(10, [], { groupId }), true)
    assert.equal(entryHasStartedOrFinishedGames(10, [], { groupId }), false)
  })

  it('permite editar una entry con solo Games pending', () => {
    const games = [
      game({ entryId: 10, otherEntryId: 11, status: 'pending' }),
      game({ entryId: 10, otherEntryId: 12, status: 'pending', extra: { id: 2 } }),
    ]

    assert.equal(canEditGroupEntry(10, games, { groupId }), true)
  })

  it('bloquea si esa entry tiene un Game in_progress', () => {
    const games = [
      game({ entryId: 10, otherEntryId: 11, status: 'pending' }),
      game({ entryId: 10, otherEntryId: 12, status: 'in_progress', extra: { id: 2 } }),
    ]

    assert.equal(canEditGroupEntry(10, games, { groupId }), false)
  })

  it('bloquea si esa entry tiene un Game finished', () => {
    const games = [game({ entryId: 10, otherEntryId: 11, status: 'finished' })]

    assert.equal(canEditGroupEntry(10, games, { groupId }), false)
    assert.equal(entryHasStartedOrFinishedGames(10, games, { groupId }), true)
  })

  it('sigue editable si el finished es de otras entries', () => {
    const games = [
      game({ entryId: 20, otherEntryId: 21, status: 'finished' }),
      game({ entryId: 10, otherEntryId: 11, status: 'pending', extra: { id: 2 } }),
    ]

    assert.equal(canEditGroupEntry(10, games, { groupId }), true)
  })

  it('reconoce entry1_id / entry2_id además de side1/side2', () => {
    const games = [
      {
        id: 1,
        group_id: groupId,
        status: 'finished',
        entry1_id: 10,
        entry2_id: 11,
      },
    ]

    assert.equal(canEditGroupEntry(10, games, { groupId }), false)
    assert.equal(canEditGroupEntry(12, games, { groupId }), true)
  })

  it('trata la pareja como una sola CompetitionEntry', () => {
    const games = [
      {
        id: 1,
        group_id: groupId,
        status: 'finished',
        side1: {
          competition_entry_id: 50,
          display_name: 'Pérez / Gómez',
          members: [
            { id: 1, first_name: 'Carlos', last_name: 'Pérez' },
            { id: 2, first_name: 'Juan', last_name: 'Gómez' },
          ],
        },
        side2: {
          competition_entry_id: 51,
          display_name: 'Ruiz / Díaz',
          members: [
            { id: 3, first_name: 'Ana', last_name: 'Ruiz' },
            { id: 4, first_name: 'Luis', last_name: 'Díaz' },
          ],
        },
      },
    ]

    assert.equal(canEditGroupEntry(50, games, { groupId }), false)
    assert.equal(canEditGroupEntry(51, games, { groupId }), false)
    assert.equal(canEditGroupEntry(1, games, { groupId }), true)
    assert.equal(
      groupEntryLockMessage({ participantKind: 'pair' }),
      'Esta pareja ya tiene partidos iniciados o finalizados y no puede quitarse ni moverse.',
    )
  })
})

describe('listMoveTargetGroups', () => {
  const groups = [
    { id: 4, name: 'Grupo D', competition_id: 1 },
    { id: 2, name: 'Grupo B', competition_id: 1 },
    { id: 1, name: 'Grupo A', competition_id: 1 },
    { id: 3, name: 'Grupo C', competition_id: 1 },
    { id: 9, name: 'Grupo A', competition_id: 2 },
  ]

  it('excluye el grupo actual', () => {
    assert.deepEqual(
      listMoveTargetGroups(groups, { currentGroupId: 4, competitionId: 1 }).map((group) => group.name),
      ['Grupo A', 'Grupo B', 'Grupo C'],
    )
  })

  it('solo ofrece grupos de la misma competencia', () => {
    const targets = listMoveTargetGroups(groups, { currentGroupId: 1, competitionId: 1 })

    assert.equal(targets.some((group) => group.id === 9), false)
    assert.deepEqual(
      targets.map((group) => group.id),
      [2, 3, 4],
    )
  })
})

describe('groupCompositionApi', () => {
  it('quita con DELETE al integrante del grupo', () => {
    assert.deepEqual(buildRemoveEntryRequest(12, 88), {
      method: 'delete',
      url: '/groups/12/players/88',
    })
  })

  it('mueve con un solo POST y el body de la API', () => {
    assert.deepEqual(buildMoveEntryRequest(12, 88, 45), {
      method: 'post',
      url: '/groups/12/move-player',
      data: {
        competition_entry_id: 88,
        target_group_id: 45,
      },
    })
  })
})
