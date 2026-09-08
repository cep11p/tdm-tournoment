export function formatGroupOriginLabel(origin) {
  const position = Number(origin?.position)
  const groupName = typeof origin?.group_name === 'string' ? origin.group_name.trim() : ''

  if (!Number.isInteger(position) || position < 1 || groupName === '') {
    return null
  }

  return `${position}.º · ${groupName}`
}
