import axios from 'axios'

const API_BASE = import.meta.env.VITE_API_URL ?? ''

export const api = axios.create({
  baseURL: API_BASE || undefined,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
})

let csrfToken: string | null = null

export function setCsrfToken(token: string | null) {
  csrfToken = token
}

api.interceptors.request.use((config) => {
  if (
    csrfToken &&
    config.method &&
    ['post', 'put', 'patch', 'delete'].includes(config.method)
  ) {
    config.headers = config.headers ?? {}
    ;(config.headers as any)['X-CSRF-Token'] = csrfToken
  }
  return config
})

export default api
