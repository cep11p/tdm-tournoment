import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  buildGroupOperationalStatus,
  isScheduleNotRequiredByWithdrawals,
} from './buildGroupOperationalStatus.js'

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

describe('buildGroupOperationalStatus con bajas', () => {
  it('sigue pidiendo generar partidos si hay 2 o más activos', () => {
    assert.deepEqual(
      buildGroupOperationalStatus({ participantCount: 3, activeParticipantCount: 2 }),
      { tone: 'muted', label: 'Sin partidos generados' },
    )
  })

  it('no exige partidos cuando las bajas dejan un solo activo', () => {
    assert.deepEqual(
      buildGroupOperationalStatus({ participantCount: 2, activeParticipantCount: 1 }),
      {
        tone: 'success',
        label: '✓ Sin partidos necesarios: el único participante activo clasifica sin jugar',
      },
    )
    assert.equal(
      buildGroupOperationalStatus({ participantCount: 3, activeParticipantCount: 1, isTeam: true })
        .label,
      '✓ Sin enfrentamientos necesarios: el único equipo activo clasifica sin jugar',
    )
  })

  it('informa que un grupo sin activos no aporta clasificados', () => {
    assert.deepEqual(
      buildGroupOperationalStatus({ participantCount: 2, activeParticipantCount: 0 }),
      { tone: 'muted', label: 'Sin participantes activos: el grupo no aporta clasificados' },
    )
    assert.equal(
      buildGroupOperationalStatus({ participantCount: 2, activeParticipantCount: 0, isTeam: true })
        .label,
      'Sin equipos activos: el grupo no aporta clasificados',
    )
  })

  it('con partidos históricos usa el estado de pendientes o finalizado', () => {
    assert.equal(
      buildGroupOperationalStatus({
        scheduleCount: 3,
        pendingCount: 0,
        participantCount: 3,
        activeParticipantCount: 1,
      }).label,
      '✓ Todos los partidos finalizados',
    )
    assert.equal(
      buildGroupOperationalStatus({
        scheduleCount: 3,
        pendingCount: 1,
        participantCount: 3,
        activeParticipantCount: 2,
      }).label,
      '⚠ 1 partido pendiente',
    )
  })

  it('un grupo con menos de 2 participantes sigue incompleto', () => {
    assert.equal(isScheduleNotRequiredByWithdrawals({ participantCount: 1, activeParticipantCount: 1 }), false)
    assert.equal(isScheduleNotRequiredByWithdrawals({ participantCount: 0, activeParticipantCount: 0 }), false)
    assert.equal(isScheduleNotRequiredByWithdrawals({ participantCount: 2, activeParticipantCount: 1 }), true)
  })
})
