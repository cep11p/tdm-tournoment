import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { buildGroupOperationalStatus } from './buildGroupOperationalStatus.js'

describe('buildGroupOperationalStatus', () => {
  it('marca el desempate antes que los partidos pendientes', () => {
    assert.deepEqual(
      buildGroupOperationalStatus({
        scheduleCount: 6,
        pendingCount: 2,
        requiresManualTiebreak: true,
      }),
      { tone: 'warning', label: '⚠ Desempate manual requerido' },
    )
  })

  it('avisa desempate desactualizado', () => {
    assert.equal(
      buildGroupOperationalStatus({ scheduleCount: 6, hasStaleManualTiebreaks: true }).label,
      '⚠ Desempate desactualizado',
    )
  })

  it('cuenta partidos o enfrentamientos pendientes', () => {
    assert.equal(
      buildGroupOperationalStatus({ scheduleCount: 6, pendingCount: 2 }).label,
      '⚠ 2 partidos pendientes',
    )
    assert.equal(
      buildGroupOperationalStatus({ scheduleCount: 3, pendingCount: 1, isTeam: true }).label,
      '⚠ 1 enfrentamiento pendiente',
    )
  })

  it('cierra el grupo cuando no queda nada pendiente', () => {
    assert.deepEqual(buildGroupOperationalStatus({ scheduleCount: 6, pendingCount: 0 }), {
      tone: 'success',
      label: '✓ Todos los partidos finalizados',
    })
  })

  it('no inventa un calendario vacío', () => {
    assert.equal(buildGroupOperationalStatus().label, 'Sin partidos generados')
    assert.equal(
      buildGroupOperationalStatus({ isTeam: true }).label,
      'Sin enfrentamientos generados',
    )
  })
})