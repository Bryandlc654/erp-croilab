import type { z } from 'zod'
import { ApiError, AUTH_EXPIRED, CSRF_EXPIRED, leerCsrf, url } from '../../shared/api/client'

/* Como api() de shared, pero con un FormData (subidas y comentarios con
   archivos): el navegador pone el Content-Type multipart con su frontera.
   Mismo manejo de CSRF, sesión caducada y errores. */
export async function enviarFormulario<S extends z.ZodType>(path: string, form: FormData, schema: S, signal?: AbortSignal): Promise<z.infer<S>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = leerCsrf()
  if (token) headers['X-CSRF-Token'] = token
  let r: Response
  try {
    r = await fetch(url(path), { method: 'POST', credentials: 'include', headers, body: form, signal })
  } catch (e) {
    if ((e as { name?: string } | null)?.name === 'AbortError') throw e
    throw new ApiError(0, 'No se puede conectar con el servidor', 'red')
  }
  const j: unknown = await r.json().catch(() => null)
  if (r.status === 401) window.dispatchEvent(new Event(AUTH_EXPIRED))
  if (r.status === 419) window.dispatchEvent(new Event(CSRF_EXPIRED))
  const o = typeof j === 'object' && j !== null && !Array.isArray(j) ? (j as Record<string, unknown>) : {}
  if (!r.ok || o.ok === false) {
    // Un 413 del servidor web llega sin JSON: el mensaje genérico no ayudaría.
    const msg = typeof o.msg === 'string' && o.msg ? o.msg : r.status === 413 ? 'Los archivos pesan demasiado.' : `No se ha podido contactar con el servidor (${r.status}).`
    throw new ApiError(r.status, msg, typeof o.error === 'string' ? o.error : null, typeof o.campo === 'string' ? o.campo : null)
  }
  const res = schema.safeParse(j)
  if (!res.success) {
    console.error(`Respuesta inesperada de POST ${path}`, res.error)
    throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (POST ${path}).`, 'contrato')
  }
  return res.data
}

/* URL de un fichero de tareas ya subido (para [[img:FN]] y adjuntos). */
export function urlFichero(fn: string) {
  return url(`/archivo.php?d=tasks&f=${encodeURIComponent(fn)}`)
}

/* URL completa de una ruta relativa del backend (archivo.php?…). */
export function urlBackend(relativa: string) {
  return url('/' + relativa.replace(/^\/+/, ''))
}
