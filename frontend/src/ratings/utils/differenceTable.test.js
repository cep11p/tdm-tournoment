import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
  DIFFERENCE_LABELS,
  differenceTableErrors,
  PUBLISH_CONFIRM_MESSAGE,
  rangeSpanLabel,
  rulesFromRows,
} from './differenceTable.js'

const points = { favorite_win: 1, underdog_win: 1 }

describe('tabla de diferencias', () => {
  it('acepta rangos contiguos con el último abierto', () => {
    const rules = [
      { min: 0, max: 24, favorite_win: 12, underdog_win: 12 },
      { min: 25, max: null, favorite_win: 0, underdog_win: 14 },
    ]

    assert.deepEqual(differenceTableErrors(rules), {})
    assert.equal(rangeSpanLabel(rules[0]), '0–24')
    assert.equal(rangeSpanLabel(rules[1]), '25 o más')
  })

  it('rechaza una tabla vacía, huecos, solapes y límites inválidos', () => {
    assert.equal(
      differenceTableErrors([])['rules'][0],
      'La tabla de diferencias no puede estar vacía.',
    )
    assert.equal(
      differenceTableErrors([{ min: 1, max: null, ...points }])['rules.0.min'][0],
      'El primer rango debe comenzar en 0.',
    )
    assert.equal(
      differenceTableErrors([
        { min: 0, max: 10, ...points },
        { min: 20, max: null, ...points },
      ])['rules.1.min'][0],
      'Los rangos deben ser contiguos, sin huecos.',
    )
    assert.equal(
      differenceTableErrors([
        { min: 0, max: 24, ...points },
        { min: 24, max: null, ...points },
      ])['rules.1.min'][0],
      'Los rangos no pueden solaparse.',
    )
    assert.equal(
      differenceTableErrors([{ min: -1, max: null, ...points }])['rules.0.min'][0],
      'El límite inferior debe ser un entero no negativo.',
    )
    assert.equal(
      differenceTableErrors([{ min: 0, max: 24, ...points }])['rules.0.max'][0],
      'El último rango no debe tener límite superior.',
    )
    assert.equal(
      differenceTableErrors([{ min: 0, max: null, favorite_win: -1, underdog_win: 1 }])[
        'rules.0.favorite_win'
      ][0],
      'La victoria del favorito debe ser un entero no negativo.',
    )
    assert.equal(
      differenceTableErrors([{ min: 0, max: null, favorite_win: 1.5, underdog_win: 1 }])[
        'rules.0.favorite_win'
      ][0],
      'La victoria del favorito debe ser un entero no negativo.',
    )
    assert.equal(
      differenceTableErrors([{
        min: 0,
        max: null,
        ...points,
        loser_loss: 4,
      }])['rules.0'][0],
      'El rango incluye datos que no forman parte de la tabla.',
    )
  })

  it('arma la tabla con el último rango sin límite y sin claves técnicas en la interfaz', () => {
    const rules = rulesFromRows([
      { min: '0', max: '24', favoriteWin: '12', underdogWin: '12', openEnded: false },
      { min: '25', max: '', favoriteWin: '10', underdogWin: '16', openEnded: true },
    ])

    assert.deepEqual(rules, [
      { min: 0, max: 24, favorite_win: 12, underdog_win: 12 },
      { min: 25, max: null, favorite_win: 10, underdog_win: 16 },
    ])
    assert.equal(Object.values(DIFFERENCE_LABELS).join(' ').includes('favorite_win'), false)
    assert.equal(DIFFERENCE_LABELS.favorite, 'Victoria del favorito')
    assert.equal(DIFFERENCE_LABELS.underdog, 'Victoria del jugador con menor rating')
    assert.equal(PUBLISH_CONFIRM_MESSAGE.includes('inmutables'), true)
  })
})
