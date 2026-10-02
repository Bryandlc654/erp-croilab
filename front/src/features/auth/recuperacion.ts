import { api, guardarCsrf } from '../../shared/api/client'
import { ComprobarEnlaceRespuesta, CsrfRespuesta, RecuperarRespuesta, VacioRespuesta } from './schemas'

/* Las rutas de recuperar la contraseña son públicas, pero los POST llevan el
   token CSRF de la sesión: se pide justo antes, como en el login. */
async function conCsrf() {
  const j = await api('/api/v1/auth/csrf', { schema: CsrfRespuesta })
  guardarCsrf(j.csrf)
}

export async function pedirEnlace(identificador: string) {
  await conCsrf()
  return api('/api/v1/auth/recuperar', { method: 'POST', body: { identificador }, schema: RecuperarRespuesta })
}

export async function comprobarEnlace(token: string, signal?: AbortSignal) {
  const q = new URLSearchParams({ token })
  return api(`/api/v1/auth/restablecer?${q}`, { schema: ComprobarEnlaceRespuesta, signal })
}

export async function restablecer(token: string, password: string) {
  await conCsrf()
  return api('/api/v1/auth/restablecer', { method: 'POST', body: { token, password }, schema: VacioRespuesta })
}

/* La misma política que la API (password_valida): solo para avisar antes de
   enviar; quien decide es el servidor. */
export const MINIMO_CONTRASENA = 6
