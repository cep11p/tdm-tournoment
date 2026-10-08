import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { buildBracketPodium, matchPair } from './bracketPodium.js'

const place = (name, origin = null) => ({ name, origin })

describe('bracketPodium', () => {
  it('sin ganador no arma par (tercer puesto pendiente)', () => {
    assert.equal(matchPair(place('Pedro'), place('Marcos'), null), null)
  })

  it('solo final no inventa tercer ni cuarto', () => {
    const podium = buildBracketPodium(
      matchPair(place('Carlos Perez', '1.º · Grupo A'), place('Juan Gomez', '1.º · Grupo B'), 1),
    )

    assert.equal(podium.champion.name, 'Carlos Perez')
    assert.equal(podium.champion.origin, '1.º · Grupo A')
    assert.equal(podium.runnerUp.name, 'Juan Gomez')
    assert.equal(podium.third, null)
    assert.equal(podium.fourth, null)
  })

  it('final y tercer puesto terminado asignan 3.º y 4.º', () => {
    const podium = buildBracketPodium(
      matchPair(place('Carlos'), place('Juan'), 2),
      matchPair(place('Pedro', '2.º · Grupo A'), place('Marcos'), 1),
    )

    assert.equal(podium.champion.name, 'Juan')
    assert.equal(podium.runnerUp.name, 'Carlos')
    assert.equal(podium.third.name, 'Pedro')
    assert.equal(podium.third.origin, '2.º · Grupo A')
    assert.equal(podium.fourth.name, 'Marcos')
  })

  it('sin final no hay podio', () => {
    assert.equal(buildBracketPodium(null), null)
  })
})
