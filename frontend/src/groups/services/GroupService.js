import httpClient from '../../services/httpClient'
import { buildApiUrl } from '../../shared/utils/downloadFileUrl'
import {
  buildMoveEntryRequest,
  buildRemoveEntryRequest,
} from '../utils/groupCompositionApi'

const unwrap = (response) => response?.data?.data

const GroupService = {
  async listByCompetition(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/groups`)
    return unwrap(response) ?? []
  },

  async create(competitionId, payload) {
    const response = await httpClient.post(`/competitions/${competitionId}/groups`, payload)
    return unwrap(response) ?? null
  },

  async listPlayers(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/players`)
    return unwrap(response) ?? []
  },

  /**
   * Asigna una participación al grupo. El backend completa el fixture
   * en la misma transacción: no llamar después a generateRoundRobin.
   */
  async assignEntry(groupId, { competition_entry_id }) {
    const response = await httpClient.post(`/groups/${groupId}/players`, {
      competition_entry_id,
    })

    return unwrap(response) ?? null
  },

  /**
   * Quita una participación del grupo. El backend reconcilia el fixture:
   * no llamar después a generateRoundRobin.
   */
  async removeEntry(groupId, competitionEntryId) {
    const { url } = buildRemoveEntryRequest(groupId, competitionEntryId)
    await httpClient.delete(url)
  },

  /**
   * Mueve una participación a otro grupo de la misma competencia.
   * Una sola request: el backend actualiza origen y destino.
   */
  async moveEntry(sourceGroupId, competitionEntryId, targetGroupId) {
    const { url, data } = buildMoveEntryRequest(
      sourceGroupId,
      competitionEntryId,
      targetGroupId,
    )
    const response = await httpClient.post(url, data)

    return unwrap(response) ?? null
  },

  async printSheet(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/print`)
    return unwrap(response) ?? null
  },

  async printCompetitionSheets(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/groups/print`)
    return unwrap(response) ?? null
  },

  printPdfDownloadUrl(groupId) {
    return buildApiUrl(`/groups/${groupId}/print/pdf?download=1`)
  },

  competitionGroupsPdfDownloadUrl(competitionId) {
    return buildApiUrl(`/competitions/${competitionId}/groups/print/pdf?download=1`)
  },

  async generateRoundRobin(groupId) {
    const response = await httpClient.post(`/groups/${groupId}/round-robin-games`)
    return unwrap(response) ?? []
  },

  /**
   * @returns {Promise<{
   *   message?: string,
   *   groups_created?: number,
   *   players_assigned?: number,
   *   games_created?: number,
   *   groups?: unknown[],
   * }|undefined>}
   */
  async generateRandomGroups(competitionId, { groups_count, seeded_entry_ids = [] }) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/groups/random-generate`,
      { groups_count, seeded_entry_ids },
    )

    return response?.data
  },

  /**
   * @returns {Promise<{
   *   message?: string,
   *   groups_removed?: number,
   *   games_removed?: number,
   *   bracket_removed?: boolean,
   *   groups_created?: number,
   *   players_assigned?: number,
   *   games_created?: number,
   *   groups?: unknown[],
   * }|undefined>}
   */
  async regenerateRandomGroups(competitionId, { groups_count, seeded_entry_ids = [] }) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/groups/regenerate-random`,
      { groups_count, seeded_entry_ids },
    )

    return response?.data
  },

  async setGroupPlayerStatus(groupId, payload) {
    const response = await httpClient.post(`/groups/${groupId}/player-status`, payload)
    return unwrap(response) ?? null
  },
}

export default GroupService
