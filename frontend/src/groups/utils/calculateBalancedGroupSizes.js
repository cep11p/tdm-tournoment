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
 * @param {number} playerCount
 * @returns {number}
 */
export function maxValidGroupsCount(playerCount) {
  if (!Number.isInteger(playerCount) || playerCount < 2) {
    return 0
  }

  return Math.floor(playerCount / 2)
}

/**
 * @param {number} playerCount
 * @param {number} groupsCount
 * @returns {boolean}
 */
export function isValidGroupDistribution(playerCount, groupsCount) {
  return (
    Number.isInteger(playerCount) &&
    Number.isInteger(groupsCount) &&
    playerCount >= 2 &&
    groupsCount >= 1 &&
    playerCount >= groupsCount * 2
  )
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

/**
 * @param {number} playerCount
 * @param {number} groupsCount
 * @returns {string}
 */
export function formatEstimatedDistributionSummary(playerCount, groupsCount) {
  if (playerCount < 2 || groupsCount < 1) {
    return ''
  }

  const playersLabel = `${playerCount} jugador${playerCount === 1 ? '' : 'es'}`
  const groupsLabel = `${groupsCount} grupo${groupsCount === 1 ? '' : 's'}`

  return `${playersLabel} · ${groupsLabel}`
}

/**
 * @param {number} playerCount
 * @param {number} groupsCount
 * @returns {string}
 */
export function formatEstimatedDistributionSizes(playerCount, groupsCount) {
  const sizes = calculateBalancedGroupSizes(playerCount, groupsCount)

  if (!sizes.length) {
    return ''
  }

  return formatBalancedGroupSizes(sizes)
}
