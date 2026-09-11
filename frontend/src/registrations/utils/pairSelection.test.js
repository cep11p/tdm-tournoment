import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { PLAYER_REGISTRATION_ROW_STATUS } from '../../players/utils/playerRegistrationRowStatus.js'
import {
  canSelectPlayer,
  emptyPairSlots,
  firstFreeSlot,
  isPairComplete,
  isPlayerAlreadySelected,
  removePlayerFromSlot,
  selectPlayer,
} from './pairSelection.js'

const compatible = PLAYER_REGISTRATION_ROW_STATUS.COMPATIBLE
const unavailable = PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE
const categoryMismatch = PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_MISMATCH

const ana = { id: 1, first_name: 'Ana', last_name: 'García' }
const juan = { id: 2, first_name: 'Juan', last_name: 'Gómez' }
const luis = { id: 3, first_name: 'Luis', last_name: 'Pérez' }

describe('emptyPairSlots / firstFreeSlot', () => {
  it('empieza con dos slots libres y el primero es el slot 1', () => {
    const slots = emptyPairSlots()

    assert.deepEqual(slots, [null, null])
    assert.equal(firstFreeSlot(slots), 0)
  })
})

describe('selectPlayer', () => {
  it('el primer jugador llena el slot 1', () => {
    const slots = selectPlayer(ana, emptyPairSlots(), compatible)

    assert.equal(slots[0], ana)
    assert.equal(slots[1], null)
    assert.equal(firstFreeSlot(slots), 1)
  })

  it('el segundo jugador llena el slot 2', () => {
    const withAna = selectPlayer(ana, emptyPairSlots(), compatible)
    const slots = selectPlayer(juan, withAna, compatible)

    assert.equal(slots[0], ana)
    assert.equal(slots[1], juan)
    assert.equal(firstFreeSlot(slots), -1)
  })

  it('un tercer jugador no entra si ambos slots están ocupados', () => {
    const full = selectPlayer(juan, selectPlayer(ana, emptyPairSlots(), compatible), compatible)

    assert.equal(canSelectPlayer(luis, full, compatible), false)
    assert.deepEqual(selectPlayer(luis, full, compatible), full)
  })

  it('el mismo jugador no puede seleccionarse dos veces', () => {
    const withAna = selectPlayer(ana, emptyPairSlots(), compatible)

    assert.equal(isPlayerAlreadySelected(ana, withAna), true)
    assert.equal(canSelectPlayer(ana, withAna, compatible), false)
    assert.deepEqual(selectPlayer(ana, withAna, compatible), withAna)
  })

  it('unavailable no puede seleccionarse', () => {
    assert.equal(canSelectPlayer(ana, emptyPairSlots(), unavailable), false)
    assert.deepEqual(selectPlayer(ana, emptyPairSlots(), unavailable), [null, null])
  })

  it('category_mismatch sí puede seleccionarse', () => {
    assert.equal(canSelectPlayer(ana, emptyPairSlots(), categoryMismatch), true)

    const slots = selectPlayer(ana, emptyPairSlots(), categoryMismatch)

    assert.equal(slots[0], ana)
    assert.equal(slots[1], null)
  })
})

describe('removePlayerFromSlot', () => {
  it('quitar un jugador libera el slot', () => {
    const full = selectPlayer(juan, selectPlayer(ana, emptyPairSlots(), compatible), compatible)
    const slots = removePlayerFromSlot(full, 0)

    assert.equal(slots[0], null)
    assert.equal(slots[1], juan)
    assert.equal(firstFreeSlot(slots), 0)
  })

  it('la siguiente selección ocupa el primer slot libre', () => {
    const full = selectPlayer(juan, selectPlayer(ana, emptyPairSlots(), compatible), compatible)
    const afterRemove = removePlayerFromSlot(full, 0)
    const slots = selectPlayer(luis, afterRemove, compatible)

    assert.equal(slots[0], luis)
    assert.equal(slots[1], juan)
  })
})

describe('isPairComplete', () => {
  it('la confirmación solo es válida con dos jugadores', () => {
    assert.equal(isPairComplete(emptyPairSlots()), false)
    assert.equal(isPairComplete(selectPlayer(ana, emptyPairSlots(), compatible)), false)
    assert.equal(
      isPairComplete(selectPlayer(juan, selectPlayer(ana, emptyPairSlots(), compatible), compatible)),
      true,
    )
  })
})
