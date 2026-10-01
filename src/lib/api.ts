export async function apiPost<T = any>(path: string, body: any, csrf: string | null) {
  const r = await fetch(path, {
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
  const r = await fetch(path, { credentials: 'include' })
  return (await r.json()) as T
}
