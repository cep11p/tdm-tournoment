import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  RATING_METHOD_DIFFERENCE_TABLE,
  ratingMethodLabel,
  ratingStatusLabel,
} from './ratingDisplay.js'

describe('ratingDisplay', () => {
  it('muestra la tabla de diferencias con una etiqueta comprensible', () => {
    assert.equal(ratingMethodLabel(RATING_METHOD_DIFFERENCE_TABLE), 'Tabla de diferencias')
    assert.equal(ratingMethodLabel('difference_table').includes('difference_table'), false)
  })

  it('no expone un código desconocido', () => {
    assert.equal(ratingMethodLabel('elo'), 'Método no definido')
  })

  it('traduce el estado', () => {
    assert.equal(ratingStatusLabel(true), 'Activo')
    assert.equal(ratingStatusLabel(false), 'Inactivo')
  })
})
