import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { buildAuditSummary } from './buildAuditSummary.js'

describe('resúmenes de sistemas de rating', () => {
  const auditLog = (action, summary = {}, context = {}) => ({
    action,
    summary: { rating_system_name: 'Rating del club', ...summary },
    context,
    subject: { label: 'Rating del club' },
  })

  it('resume la creación', () => {
    assert.equal(
      buildAuditSummary(auditLog('rating_system.created')),
      'Sistema de rating "Rating del club" creado',
    )
  })

  it('resume la modificación con los campos cambiados', () => {
    assert.equal(
      buildAuditSummary(
        auditLog('rating_system.updated', {
          changed_fields: ['name', 'initial_rating', 'method'],
        }),
      ),
      'Se modificaron nombre, rating inicial y método',
    )
  })

  it('resume la activación y la desactivación', () => {
    assert.equal(
      buildAuditSummary(auditLog('rating_system.activated')),
      'Sistema de rating "Rating del club" activado',
    )
    assert.equal(
      buildAuditSummary(auditLog('rating_system.deactivated')),
      'Sistema de rating "Rating del club" desactivado',
    )
  })

  it('usa el contexto cuando el resumen no trae el nombre', () => {
    assert.equal(
      buildAuditSummary({
        action: 'rating_system.created',
        summary: {},
        context: { rating_system_name: 'Copa' },
      }),
      'Sistema de rating "Copa" creado',
    )
  })

  it('mantiene el resumen de un ranking creado', () => {
    assert.equal(
      buildAuditSummary({
        action: 'ranking.created',
        summary: { ranking_name: 'Singles 2027' },
      }),
      'Ranking "Singles 2027" creado',
    )
  })
})
