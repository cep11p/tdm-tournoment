/**
 * Calcula tamaños balanceados por grupo.
 *
 * @param {number} playerCount
 * @param {number} groupsCount
 * @returns {number[]}
 */
export function calculateBalancedGroupSizes(playerCount, groupsCount) {
  if (
    !Number.isInteger(playerCount) ||
    !Number.isInteger(groupsCount) ||
    playerCount < 1 ||
    groupsCount < 1 ||
    groupsCount > playerCount
  ) {
    return []
  }

  const baseSize = Math.floor(playerCount / groupsCount)
  const remainder = playerCount % groupsCount
  const sizes = []

  for (let index = 0; index < groupsCount; index += 1) {
    sizes.push(baseSize + (index < remainder ? 1 : 0))
  }

  return sizes
}

/**
 * @param {number[]} sizes
 * @returns {string}
 */
export function formatBalancedGroupSizes(sizes) {
  if (!sizes.length) {
    return ''
  }

  if (sizes.length === 1) {
    return `${sizes[0]} jugador${sizes[0] === 1 ? '' : 'es'}`
  }

  const lastSize = sizes[sizes.length - 1]
  const prefix = sizes.slice(0, -1).join(', ')

  return `${prefix} y ${lastSize} jugador${lastSize === 1 ? '' : 'es'}`
}
