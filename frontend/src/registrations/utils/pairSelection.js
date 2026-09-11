import { isPlayerRegistrationRowSelectable } from '../../players/utils/playerRegistrationRowStatus.js'

function normalizeSlots(slots) {
  const first = Array.isArray(slots) ? (slots[0] ?? null) : null
  const second = Array.isArray(slots) ? (slots[1] ?? null) : null

  return [first, second]
}

export function emptyPairSlots() {
  return [null, null]
}

export function firstFreeSlot(slots) {
  return normalizeSlots(slots).findIndex((slot) => slot == null)
}

export function isPlayerAlreadySelected(player, slots) {
  if (player?.id == null) {
    return false
  }

  return normalizeSlots(slots).some((slot) => slot?.id === player.id)
}

export function canSelectPlayer(player, slots, status) {
  if (player?.id == null) {
    return false
  }

  if (!isPlayerRegistrationRowSelectable(status)) {
    return false
  }

  if (isPlayerAlreadySelected(player, slots)) {
    return false
  }

  return firstFreeSlot(slots) !== -1
}

export function selectPlayer(player, slots, status) {
  const current = normalizeSlots(slots)

  if (!canSelectPlayer(player, current, status)) {
    return current
  }

  const next = [...current]
  next[firstFreeSlot(current)] = player

  return next
}

export function removePlayerFromSlot(slots, index) {
  const current = normalizeSlots(slots)

  if (index !== 0 && index !== 1) {
    return current
  }

  const next = [...current]
  next[index] = null

  return next
}

export function isPairComplete(slots) {
  const [first, second] = normalizeSlots(slots)

  return first?.id != null && second?.id != null && first.id !== second.id
}
