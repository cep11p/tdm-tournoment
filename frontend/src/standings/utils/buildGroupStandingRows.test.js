import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { buildGroupStandingRows } from './buildGroupStandingRows.js'

const standing = (overrides = {}) => ({
  competition_entry_id: 1,
  display_name: 'Ana Ruiz',
  player_name: 'No usar',
  members: [],
  played: 0,
  won: 0,
  lost: 0,
  group_player_status: 'active',
  eligible_for_qualification: true,
  requires_manual_tiebreak: false,
  manual_tiebreak_applied: false,
  ...overrides,
})

describe('buildGroupStandingRows', () => {
  it('respeta el orden recibido y numera desde 1', () => {
    const rows = buildGroupStandingRows({
      standings: [
        standing({ competition_entry_id: 8, display_name: 'Zulema' }),
        standing({ competition_entry_id: 3, display_name: 'Ana' }),
      ],
      meta: { standings_are_provisional: true },
      qualifiedPerGroup: 1,
    })

    assert.deepEqual(
      rows.map((row) => [row.position, row.displayName, row.competitionEntryId]),
      [
        [1, 'Zulema', 8],
        [2, 'Ana', 3],
      ],
    )
  })

  it('usa display_name en singles y no player_name', () => {
    const [row] = buildGroupStandingRows({
      standings: [
        standing({
          display_name: 'Ana Ruiz',
          player_name: 'Otro nombre',
          members: [{ first_name: 'Ana', last_name: 'Ruiz' }],
          played: 3,
          won: 2,
          lost: 1,
        }),
      ],
      qualifiedPerGroup: 2,
    })

    assert.equal(row.displayName, 'Ana Ruiz')
    assert.equal(row.membersLabel, null)
    assert.equal(row.played, 3)
    assert.equal(row.won, 2)
    assert.equal(row.lost, 1)
  })

  it('ignora player_name cuando no hay display_name', () => {
    const [row] = buildGroupStandingRows({
      standings: [
        standing({
          display_name: '   ',
          player_name: 'No debe usarse',
        }),
      ],
    })

    assert.equal(row.displayName, 'Participación')
  })

  it('muestra los integrantes de una pareja cuando el nombre no los repite', () => {
    const [row] = buildGroupStandingRows({
      standings: [
        standing({
          display_name: 'Pareja 1',
          members: [
            { first_name: 'Ana', last_name: 'Ruiz' },
            { first_name: 'Luis', last_name: 'Díaz' },
          ],
          played: 2,
          won: 2,
          lost: 0,
        }),
      ],
      meta: {},
      qualifiedPerGroup: 2,
    })

    assert.equal(row.displayName, 'Pareja 1')
    assert.equal(row.membersLabel, 'Ana Ruiz · Luis Díaz')
    assert.equal(row.qualification.kind, 'qualified')
  })

  it('no repite integrantes si display_name ya es la pareja', () => {
    const [row] = buildGroupStandingRows({
      standings: [
        standing({
          display_name: 'Ana Ruiz / Luis Díaz',
          members: [
            { first_name: 'Ana', last_name: 'Ruiz' },
            { first_name: 'Luis', last_name: 'Díaz' },
          ],
        }),
      ],
      meta: { standings_are_provisional: false, completed_games_count: 1, total_games_count: 1 },
      qualifiedPerGroup: 1,
    })

    assert.equal(row.membersLabel, null)
    assert.equal(row.qualification.kind, 'qualified')
  })

  it('resume el plantel de un equipo', () => {
    const [row] = buildGroupStandingRows({
      standings: [
        standing({
          display_name: 'Los Halcones',
          player_name: 'Capitán',
          members: [
            { first_name: 'Ana', last_name: 'Ruiz' },
            { first_name: 'Luis', last_name: 'Díaz' },
            { first_name: 'Marta', last_name: 'López' },
          ],
          played: 1,
          won: 1,
          lost: 0,
          rubber_difference: 2,
        }),
      ],
      meta: {
        standings_are_provisional: false,
        total_team_ties_count: 1,
        finished_team_ties_count: 1,
      },
      qualifiedPerGroup: 1,
    })

    assert.equal(row.displayName, 'Los Halcones')
    assert.equal(row.membersLabel, '3 integrantes')
    assert.equal(row.played, 1)
    assert.equal(row.won, 1)
    assert.equal(row.lost, 0)
    assert.equal(row.rubberDifference, 2)
    assert.equal(row.qualification.kind, 'qualified')
  })

  it('marca la zona de clasificación provisoria según qualified_per_group', () => {
    const rows = buildGroupStandingRows({
      standings: [
        standing({ competition_entry_id: 1, display_name: 'Primero' }),
        standing({ competition_entry_id: 2, display_name: 'Segundo' }),
        standing({ competition_entry_id: 3, display_name: 'Tercero' }),
      ],
      meta: { standings_are_provisional: true },
      qualifiedPerGroup: 2,
    })

    assert.deepEqual(
      rows.map((row) => row.qualification.kind),
      ['provisional_qualified', 'provisional_qualified', 'provisional_eliminated'],
    )
  })

  it('delega el desempate manual que cruza el corte', () => {
    const rows = buildGroupStandingRows({
      standings: [
        standing({ competition_entry_id: 10, display_name: 'Primero', played: 3, won: 3, lost: 0 }),
        standing({
          competition_entry_id: 11,
          display_name: 'Empate A',
          played: 3,
          won: 1,
          lost: 2,
          requires_manual_tiebreak: true,
        }),
        standing({
          competition_entry_id: 12,
          display_name: 'Empate B',
          played: 3,
          won: 1,
          lost: 2,
          requires_manual_tiebreak: true,
        }),
      ],
      meta: {
        standings_are_provisional: false,
        requires_manual_tiebreak: true,
        manual_tiebreak_groups: [{ entry_ids: [11, 12] }],
      },
      qualifiedPerGroup: 2,
    })

    assert.deepEqual(
      rows.map((row) => [row.displayName, row.qualification.kind, row.played, row.won, row.lost]),
      [
        ['Primero', 'qualified', 3, 3, 0],
        ['Empate A', 'tiebreak_pending', 3, 1, 2],
        ['Empate B', 'tiebreak_pending', 3, 1, 2],
      ],
    )
  })
})
