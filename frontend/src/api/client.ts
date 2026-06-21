// Single HTTP utility for all AIRR API calls. Never create other fetch/axios instances.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''

export type ApiError = { code: string; message: string; details?: unknown }

export class ApiException extends Error {
  status: number
  error: ApiError
  constructor(status: number, error: ApiError) {
    super(error.message)
    this.status = status
    this.error = error
  }
}

function token(): string | null {
  return localStorage.getItem('airr_token')
}

export function setToken(t: string | null) {
  if (t) localStorage.setItem('airr_token', t)
  else localStorage.removeItem('airr_token')
}

export async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(options.headers as Record<string, string>),
  }
  const t = token()
  if (t) headers.Authorization = `Bearer ${t}`

  const res = await fetch(`${API_BASE_URL}/api${path}`, { ...options, headers })

  if (res.status === 204) return undefined as T

  const json = await res.json().catch(() => null)

  if (!res.ok) {
    const error: ApiError = json?.error ?? { code: 'UNKNOWN', message: res.statusText }
    throw new ApiException(res.status, error)
  }
  return json as T
}
