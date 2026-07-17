import httpClient from './httpClient'

const unwrap = (response) => response?.data?.data ?? null

const AuthService = {
  async getProfile() {
    const response = await httpClient.get('/me', { skipAuthRedirect: true })
    return unwrap(response)
  },
}

export default AuthService
