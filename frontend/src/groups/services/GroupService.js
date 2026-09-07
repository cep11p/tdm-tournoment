import httpClient from '../../services/httpClient'
import { buildApiUrl } from '../../shared/utils/downloadFileUrl'

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
  async generateRandomGroups(competitionId, { groups_count }) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/groups/random-generate`,
      { groups_count },
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
  async regenerateRandomGroups(competitionId, { groups_count }) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/groups/regenerate-random`,
      { groups_count },
    )

    return response?.data
  },

  async setGroupPlayerStatus(groupId, payload) {
    const response = await httpClient.post(`/groups/${groupId}/player-status`, payload)
    return unwrap(response) ?? null
  },
}

export default GroupService
