const API_BASE = import.meta.env.VITE_API_URL ?? ''

function url(p: string) {
  if (p.startsWith('http')) return p
  const base = API_BASE.replace(/\/$/, '')
  const path = p.startsWith('/') ? p : '/' + p
  return base + path
}

export async function apiPost<T = any>(path: string, body: any, csrf: string | null) {
  const r = await fetch(url(path), {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
    },
    body: JSON.stringify(body),
  })
  return (await r.json()) as T
}

export async function apiGet<T = any>(path: string) {
  const r = await fetch(url(path), { credentials: 'include' })
  return (await r.json()) as T
}
