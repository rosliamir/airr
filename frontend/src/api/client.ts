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

// Multipart upload (FormData) — never set Content-Type manually so the browser
// adds the multipart boundary. Shares auth + error handling with apiRequest.
export async function uploadFile<T>(path: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const t = token()
  if (t) headers.Authorization = `Bearer ${t}`

  const res = await fetch(`${API_BASE_URL}/api${path}`, { method: 'POST', body: form, headers })
  const json = await res.json().catch(() => null)
  if (!res.ok) {
    const error: ApiError = json?.error ?? { code: 'UNKNOWN', message: res.statusText }
    throw new ApiException(res.status, error)
  }
  return json as T
}

// Authenticated file download — window.open()/plain <a href> can't attach the
// Bearer token (auth is header-based, not cookie-based), so any endpoint that
// returns a file must go through fetch() + Blob like this instead.
export async function downloadFile(path: string, suggestedName = 'download'): Promise<void> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const t = token()
  if (t) headers.Authorization = `Bearer ${t}`

  const res = await fetch(`${API_BASE_URL}/api${path}`, { headers })
  if (!res.ok) {
    const json = await res.json().catch(() => null)
    const error: ApiError = json?.error ?? { code: 'UNKNOWN', message: res.statusText }
    throw new ApiException(res.status, error)
  }

  const disposition = res.headers.get('Content-Disposition') ?? ''
  const match = disposition.match(/filename="?([^"]+)"?/)
  const filename = match?.[1] ?? suggestedName

  const blob = await res.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
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
