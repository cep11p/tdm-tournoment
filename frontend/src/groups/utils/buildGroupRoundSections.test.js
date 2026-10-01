import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  buildGroupRoundSections,
  mergeRoundOpenState,
  roundSectionKey,
} from './buildGroupRoundSections.js'

const game = (overrides) => ({
  id: overrides.id,
  group_round: overrides.group_round,
  group_match: overrides.group_match,
  status: overrides.status ?? 'pending',
  is_bye: overrides.is_bye ?? false,
})

describe('buildGroupRoundSections games', () => {
  it('agrupa por ronda en orden ascendente y por group_match', () => {
    const sections = buildGroupRoundSections(
      [
        game({ id: 4, group_round: 2, group_match: 2, status: 'pending' }),
        game({ id: 1, group_round: 1, group_match: 2, status: 'finished' }),
        game({ id: 3, group_round: 2, group_match: 1, status: 'finished' }),
        game({ id: 2, group_round: 1, group_match: 1, status: 'pending' }),
      ],
      { kind: 'games' },
    )

    assert.deepEqual(
      sections.map((section) => section.roundNumber),
      [1, 2],
    )
    assert.deepEqual(
      sections[0].items.map((item) => item.id),
      [2, 1],
    )
    assert.deepEqual(
      sections[1].items.map((item) => item.id),
      [3, 4],
    )
  })

  it('mezcla finalizados y pendientes en la misma ronda', () => {
    const [section] = buildGroupRoundSections(
      [
        game({ id: 1, group_round: 1, group_match: 1, status: 'finished' }),
        game({ id: 2, group_round: 1, group_match: 2, status: 'pending' }),
        game({ id: 3, group_round: 1, group_match: 3, status: 'in_progress' }),
      ],
      { kind: 'games' },
    )

    assert.equal(section.totalCount, 3)
    assert.equal(section.completedCount, 1)
    assert.equal(section.pendingCount, 2)
    assert.equal(section.isFinished, false)
    assert.deepEqual(
      section.items.map((item) => item.status),
      ['finished', 'pending', 'in_progress'],
    )
  })

  it('pluraliza partido y pendiente', () => {
    const [onePending] = buildGroupRoundSections(
      [
        game({ id: 1, group_round: 2, group_match: 1, status: 'finished' }),
        game({ id: 2, group_round: 2, group_match: 2, status: 'finished' }),
        game({ id: 3, group_round: 2, group_match: 3, status: 'pending' }),
      ],
      { kind: 'games' },
    )

    assert.equal(onePending.label, 'Ronda 2')
    assert.equal(onePending.countLabel, '3 partidos')
    assert.equal(onePending.statusLabel, '1 pendiente')

    const [twoPending] = buildGroupRoundSections(
      [
        game({ id: 1, group_round: 3, group_match: 1, status: 'pending' }),
        game({ id: 2, group_round: 3, group_match: 2, status: 'in_progress' }),
        game({ id: 3, group_round: 3, group_match: 3, status: 'finished' }),
      ],
      { kind: 'games' },
    )

    assert.equal(twoPending.countLabel, '3 partidos')
    assert.equal(twoPending.statusLabel, '2 pendientes')
    assert.equal(twoPending.pendingCount, 2)

    const [single] = buildGroupRoundSections(
      [game({ id: 1, group_round: 1, group_match: 1, status: 'pending' })],
      { kind: 'games' },
    )

    assert.equal(single.countLabel, '1 partido')
    assert.equal(single.statusLabel, '1 pendiente')
  })

  it('marca Finalizada cuando todos los partidos de la ronda terminaron', () => {
    const [section] = buildGroupRoundSections(
      [
        game({ id: 1, group_round: 1, group_match: 1, status: 'finished' }),
        game({ id: 2, group_round: 1, group_match: 2, status: 'finished' }),
        game({ id: 3, group_round: 1, group_match: 3, status: 'finished' }),
      ],
      { kind: 'games' },
    )

    assert.equal(section.isFinished, true)
    assert.equal(section.pendingCount, 0)
    assert.equal(section.completedCount, 3)
    assert.equal(section.statusLabel, 'Finalizada')
    assert.equal(section.countLabel, '3 partidos')
    assert.equal(section.defaultOpen, false)
  })

  it('excluye bye de la ronda y de los conteos', () => {
    const sections = buildGroupRoundSections(
      [
        game({ id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: true }),
        game({ id: 2, group_round: 1, group_match: 2, status: 'pending' }),
        game({ id: 3, group_round: 1, group_match: 3, status: 'finished' }),
      ],
      { kind: 'games' },
    )

    assert.equal(sections.length, 1)
    assert.deepEqual(
      sections[0].items.map((item) => item.id),
      [2, 3],
    )
    assert.equal(sections[0].totalCount, 2)
    assert.equal(sections[0].pendingCount, 1)
    assert.equal(sections[0].completedCount, 1)
    assert.equal(sections[0].countLabel, '2 partidos')
    assert.equal(sections[0].statusLabel, '1 pendiente')
  })

  it('no inventa Ronda 0 cuando group_round es null', () => {
    const sections = buildGroupRoundSections(
      [
        game({ id: 2, group_round: null, group_match: null, status: 'pending' }),
        game({ id: 1, group_round: 1, group_match: 1, status: 'finished' }),
      ],
      { kind: 'games' },
    )

    assert.equal(sections[0].label, 'Ronda 1')
    assert.equal(sections[1].roundNumber, null)
    assert.equal(sections[1].label, 'Sin ronda asignada')
    assert.equal(roundSectionKey(sections[1]), 'unassigned')
    assert.notEqual(sections[1].label, 'Ronda 0')
  })

  it('no cuenta not_needed como pendiente ni como finalizado', () => {
    const [section] = buildGroupRoundSections(
      [game({ id: 1, group_round: 1, group_match: 1, status: 'not_needed' })],
      { kind: 'games' },
    )

    assert.equal(section.pendingCount, 0)
    assert.equal(section.completedCount, 0)
    assert.equal(section.isFinished, false)
    assert.equal(section.statusLabel, '0 pendientes')
    assert.equal(section.defaultOpen, false)
  })
})

describe('buildGroupRoundSections team ties', () => {
  it('usa enfrentamiento y enfrentamientos', () => {
    const [single] = buildGroupRoundSections(
      [{ id: 1, group_round: 1, group_match: 1, status: 'pending' }],
      { kind: 'team_ties' },
    )
    const [many] = buildGroupRoundSections(
      [
        { id: 1, group_round: 1, group_match: 1, status: 'finished' },
        { id: 2, group_round: 1, group_match: 2, status: 'ready' },
      ],
      { kind: 'team_ties' },
    )

    assert.equal(single.countLabel, '1 enfrentamiento')
    assert.equal(single.statusLabel, '1 pendiente')
    assert.equal(many.countLabel, '2 enfrentamientos')
    assert.equal(many.pendingCount, 1)
    assert.equal(many.completedCount, 1)
    assert.equal(many.isFinished, false)
    assert.equal(many.statusLabel, '1 pendiente')
  })

  it('marca Finalizada y no descarta un registro aunque tenga is_bye', () => {
    const [section] = buildGroupRoundSections(
      [
        { id: 1, group_round: 1, group_match: 1, status: 'finished', is_bye: true },
        { id: 2, group_round: 1, group_match: 2, status: 'finished' },
      ],
      { kind: 'team_ties' },
    )

    assert.equal(section.totalCount, 2)
    assert.equal(section.isFinished, true)
    assert.equal(section.statusLabel, 'Finalizada')
    assert.equal(section.countLabel, '2 enfrentamientos')
  })

  it('no cuenta cancelado como pendiente', () => {
    const [section] = buildGroupRoundSections(
      [
        { id: 1, group_round: 1, group_match: 1, status: 'cancelled' },
        { id: 2, group_round: 1, group_match: 2, status: 'in_progress' },
      ],
      { kind: 'team_ties' },
    )

    assert.equal(section.pendingCount, 1)
    assert.equal(section.completedCount, 0)
    assert.equal(section.isFinished, false)
    assert.equal(section.statusLabel, '1 pendiente')
  })
})

describe('default open', () => {
  const rounds = (statusesByRound) =>
    statusesByRound.flatMap((statuses, index) =>
      statuses.map((status, matchIndex) =>
        game({
          id: index * 10 + matchIndex + 1,
          group_round: index + 1,
          group_match: matchIndex + 1,
          status,
        }),
      ),
    )

  it('abre solo la primera ronda si todas tienen pendientes', () => {
    const sections = buildGroupRoundSections(
      rounds([
        ['pending', 'pending'],
        ['pending', 'pending'],
        ['in_progress', 'pending'],
      ]),
      { kind: 'games' },
    )

    assert.deepEqual(
      sections.map((section) => section.defaultOpen),
      [true, false, false],
    )
  })

  it('cierra las finalizadas anteriores y abre la primera con pendientes', () => {
    const sections = buildGroupRoundSections(
      rounds([
        ['finished', 'finished'],
        ['pending', 'finished'],
        ['pending', 'pending'],
      ]),
      { kind: 'games' },
    )

    assert.deepEqual(
      sections.map((section) => [section.roundNumber, section.defaultOpen, section.isFinished]),
      [
        [1, false, true],
        [2, true, false],
        [3, false, false],
      ],
    )
  })

  it('abre la ronda 3 cuando las dos primeras están finalizadas', () => {
    const sections = buildGroupRoundSections(
      rounds([['finished'], ['finished'], ['pending'], ['pending']]),
      { kind: 'games' },
    )

    assert.deepEqual(
      sections.map((section) => section.defaultOpen),
      [false, false, true, false],
    )
  })

  it('cierra todas cuando el grupo está finalizado', () => {
    const sections = buildGroupRoundSections(
      rounds([
        ['finished', 'finished'],
        ['finished', 'finished'],
      ]),
      { kind: 'games' },
    )

    assert.equal(sections.every((section) => section.statusLabel === 'Finalizada'), true)
    assert.deepEqual(
      sections.map((section) => section.defaultOpen),
      [false, false],
    )
  })
})

describe('mergeRoundOpenState', () => {
  const section = (roundNumber, defaultOpen) => ({ roundNumber, defaultOpen })

  it('inicializa cada ronda una sola vez y no pisa una elección previa', () => {
    const initial = mergeRoundOpenState({}, [
      section(1, false),
      section(2, true),
      section(3, false),
    ])

    assert.deepEqual(initial, { 1: false, 2: true, 3: false })

    const afterManualClose = { ...initial, 2: false, 1: true }
    const refreshed = mergeRoundOpenState(afterManualClose, [
      section(1, true),
      section(2, false),
      section(3, true),
    ])

    assert.equal(refreshed, afterManualClose)
    assert.deepEqual(refreshed, { 1: true, 2: false, 3: false })
  })

  it('inicializa solo la ronda nueva', () => {
    const current = { 1: false, 2: false }
    const merged = mergeRoundOpenState(current, [
      section(1, true),
      section(2, true),
      section(4, true),
    ])

    assert.deepEqual(merged, { 1: false, 2: false, 4: true })
    assert.equal(current[4], undefined)
  })
})
