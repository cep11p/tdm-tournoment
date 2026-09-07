import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const CompetitionCheckInService = {
  async get(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/check-in`)
    return unwrap(response) ?? null
  },

  async checkIn(competitionId, memberId) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/check-in/members/${memberId}`,
    )
    return unwrap(response) ?? null
  },

  async undoCheckIn(competitionId, memberId) {
    const response = await httpClient.delete(
      `/competitions/${competitionId}/check-in/members/${memberId}`,
    )
    return unwrap(response) ?? null
  },

  async markAllPresent(competitionId) {
    const response = await httpClient.post(`/competitions/${competitionId}/check-in/bulk`, {
      action: 'mark_all_present',
    })
    return unwrap(response) ?? null
  },

  async clearAll(competitionId) {
    const response = await httpClient.post(`/competitions/${competitionId}/check-in/bulk`, {
      action: 'clear_all',
    })
    return unwrap(response) ?? null
  },
}

export default CompetitionCheckInService
