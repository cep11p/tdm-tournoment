/**
 * Contratos de request para mutar integrantes. El backend reconcilia el fixture.
 * Move es un único POST: no combinar DELETE + assign.
 */

export function buildRemoveEntryRequest(groupId, competitionEntryId) {
  return {
    method: 'delete',
    url: `/groups/${groupId}/players/${competitionEntryId}`,
  }
}

export function buildMoveEntryRequest(sourceGroupId, competitionEntryId, targetGroupId) {
  return {
    method: 'post',
    url: `/groups/${sourceGroupId}/move-player`,
    data: {
      competition_entry_id: Number(competitionEntryId),
      target_group_id: Number(targetGroupId),
    },
  }
}
