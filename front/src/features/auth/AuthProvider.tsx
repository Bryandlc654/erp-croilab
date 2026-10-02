import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, AUTH_EXPIRED, CSRF_EXPIRED, guardarCsrf } from '../../shared/api/client'
import { CsrfRespuesta, LoginRespuesta, MeRespuesta, VacioRespuesta, type Me } from './schemas'
import { AuthContext, type AuthCtx } from './useAuth'

/* /me trae también el token CSRF de la sesión: con una sola petición no se
   abren dos sesiones distintas cuyo token no casaría (pasaba con csrf y me en
   paralelo). Sin sesión, el token lo pide login() justo antes de entrar. */
async function pedirSesion(): Promise<Me | null> {
  try {
    const j = await api('/api/v1/me', { schema: MeRespuesta })
    guardarCsrf(j.csrf)
    return j.me
  } catch {
    return null
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient()
  const [me, setMe] = useState<Me | null>(null)
  const [loading, setLoading] = useState(true)

  const pedirCsrf = useCallback(async () => {
    try {
      const j = await api('/api/v1/auth/csrf', { schema: CsrfRespuesta })
      guardarCsrf(j.csrf)
      return j.csrf
    } catch {
      return null
    }
  }, [])

  const refresh = useCallback(async () => {
    setMe(await pedirSesion())
    setLoading(false)
  }, [])

  useEffect(() => {
    let vivo = true
    void pedirSesion().then((m) => {
      if (!vivo) return
      setMe(m)
      setLoading(false)
    })
    return () => {
      vivo = false
    }
  }, [])

  useEffect(() => {
    // Cualquier llamada que reciba 401 cierra la sesión en el front (ProtectedRoute
    // manda al login) y tira la caché, que era de esa sesión; un 419 pide un
    // token nuevo para el siguiente intento.
    const onAuth = () => {
      setMe(null)
      qc.clear()
    }
    const onCsrf = () => void pedirCsrf()
    window.addEventListener(AUTH_EXPIRED, onAuth)
    window.addEventListener(CSRF_EXPIRED, onCsrf)
    return () => {
      window.removeEventListener(AUTH_EXPIRED, onAuth)
      window.removeEventListener(CSRF_EXPIRED, onCsrf)
    }
  }, [qc, pedirCsrf])

  const login = useCallback<AuthCtx['login']>(
    async (u, p) => {
      try {
        // Token recién pedido y en serie antes del login: el de memoria puede ser
        // de otra sesión (logout, StrictMode) y el servidor respondería 419.
        await pedirCsrf()
        const j = await api('/api/v1/auth/login', { method: 'POST', body: { username: u, password: p }, schema: LoginRespuesta })
        guardarCsrf(j.csrf)
        // Nada de lo que hubiera en caché debe verse con la sesión nueva.
        qc.clear()
        setMe(j.me)
        return { ok: true }
      } catch (e) {
        return { ok: false, msg: e instanceof ApiError ? e.message : 'No se puede conectar con el servidor' }
      }
    },
    [pedirCsrf, qc],
  )

  const logout = useCallback(async () => {
    try {
      await api('/api/v1/auth/logout', { method: 'POST', schema: VacioRespuesta })
    } catch {
      // Aunque el servidor no conteste, en este navegador la sesión se da por cerrada.
    } finally {
      // La sesión del servidor ya no existe: su token y sus datos tampoco valen.
      guardarCsrf(null)
      setMe(null)
      qc.clear()
    }
  }, [qc])

  const can = useCallback(
    (permiso: string) => {
      const p = me?.permisos ?? []
      return p.includes('admin.total') || p.includes(permiso)
    },
    [me],
  )

  const valor = useMemo<AuthCtx>(() => ({ me, loading, login, logout, refresh, can }), [me, loading, login, logout, refresh, can])
  return <AuthContext.Provider value={valor}>{children}</AuthContext.Provider>
}
