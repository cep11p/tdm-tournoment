const letterName = (letter) => `Grupo ${letter}`

/**
 * Sugiere el primer nombre A–Z libre, con el estilo existente "Grupo A".
 * Si A–Z están ocupados, usa "Grupo 27", "Grupo 28", …
 *
 * @param {Array<string|{name?: string}>} groupsOrNames
 * @returns {string}
 */
export function suggestNextGroupName(groupsOrNames = []) {
  const used = new Set(
    groupsOrNames
      .map((item) => (typeof item === 'string' ? item : item?.name))
      .filter((name) => typeof name === 'string')
      .map((name) => name.trim().toLocaleLowerCase()),
  )

  for (let index = 0; index < 26; index += 1) {
    const letter = String.fromCharCode(65 + index)
    const candidate = letterName(letter)

    if (!used.has(candidate.toLocaleLowerCase())) {
      return candidate
    }
  }

  let ordinal = 27

  while (used.has(`grupo ${ordinal}`)) {
    ordinal += 1
  }

  return `Grupo ${ordinal}`
}
