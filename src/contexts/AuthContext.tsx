import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

export type Me = { id: number; username: string; role?: string | null } | null

type AuthCtx = {
  me: Me
  loading: boolean
  csrf: string | null
  login: (u: string, p: string) => Promise<{ ok: boolean; msg?: string }>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const Ctx = createContext<AuthCtx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me>(null)
  const [loading, setLoading] = useState(true)
  const [csrf, setCsrf] = useState<string | null>(null)

  async function fetchCsrf() {
    try {
      const r = await fetch('/api/v1/auth/csrf.php', { credentials: 'include' })
      const j = await r.json()
      if (j.ok) setCsrf(j.csrf)
    } catch {}
  }

  async function refresh() {
    try {
      const r = await fetch('/api/v1/me.php', { credentials: 'include' })
      const j = await r.json()
      if (j.ok && j.me) setMe(j.me)
      else setMe(null)
    } catch {
      setMe(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchCsrf()
    refresh()
  }, [])

  async function login(u: string, p: string) {
    const r = await fetch('/api/v1/auth/login.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-Token': csrf } : {}) },
      body: JSON.stringify({ username: u, password: p }),
    })
    const j = await r.json()
    if (j.ok) {
      setMe(j.me ?? null)
      if (j.csrf) setCsrf(j.csrf)
      return { ok: true }
    }
    return { ok: false, msg: j.msg || 'Error' }
  }

  async function logout() {
    await fetch('/api/v1/auth/logout.php', {
      method: 'POST',
      credentials: 'include',
      headers: csrf ? { 'X-CSRF-Token': csrf } : {},
    })
    setMe(null)
  }

  return <Ctx.Provider value={{ me, loading, csrf, login, logout, refresh }}>{children}</Ctx.Provider>
}

export function useAuth() {
  const v = useContext(Ctx)
  if (!v) throw new Error('useAuth must be used within AuthProvider')
  return v
}
