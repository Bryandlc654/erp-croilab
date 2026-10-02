import type { z } from 'zod'

const API_BASE = import.meta.env.VITE_API_URL ?? ''

export function url(p: string) {
  if (p.startsWith('http')) return p
  const base = API_BASE.replace(/\/$/, '')
  const path = p.startsWith('/') ? p : '/' + p
  return base + path
}

/* Ruta con su query string. Los valores vacíos no se mandan: para la API
   «sin parámetro» y «parámetro vacío» no siempre significan lo mismo. */
export function conQuery(path: string, params: Record<string, string | number | boolean | null | undefined>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue
    q.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
  }
  const s = q.toString()
  return s ? `${path}?${s}` : path
}

/* Error de la API con su código HTTP. msg es el texto que manda el servidor
   (ya pensado para enseñarlo) o uno genérico si la respuesta no era JSON.
   `codigo` es el campo `error` del servidor ('sesion', 'csrf', 'validacion'…),
   'red' si no hubo respuesta o 'contrato' si no tenía la forma esperada. */
export class ApiError extends Error {
  readonly status: number
  readonly codigo: string | null
  constructor(status: number, msg: string, codigo: string | null = null) {
    super(msg)
    this.name = 'ApiError'
    this.status = status
    this.codigo = codigo
  }
}

/* Avisos globales: el AuthProvider escucha estos eventos para cerrar la sesión
   (401) o pedir un token CSRF nuevo (419) sin que cada pantalla lo gestione. */
export const AUTH_EXPIRED = 'croilab:auth-expired'
export const CSRF_EXPIRED = 'croilab:csrf-expired'

/* El token CSRF vive aquí y no en el estado de React: así cualquier mutación
   (también las de TanStack Query) lo manda sin tener que pasarlo a mano. */
let csrfActual: string | null = null
export function guardarCsrf(token: string | null) {
  csrfActual = token
}
export function leerCsrf() {
  return csrfActual
}

const SIN_CONEXION = 'No se puede conectar con el servidor'

type Metodo = 'GET' | 'POST' | 'PATCH' | 'DELETE'
type Opciones<S extends z.ZodType> = {
  method?: Metodo
  body?: unknown
  /* Forma esperada de la respuesta: si no cuadra se lanza ApiError en vez de
     dejar que la pantalla pinte datos a medias. */
  schema: S
  signal?: AbortSignal
}

function esObjeto(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

// Una respuesta que no es JSON (500 con HTML, proxy, bloqueo CORS) se trata como fallo, no como excepción.
async function leerJson(r: Response): Promise<unknown> {
  try {
    return await r.json()
  } catch {
    return null
  }
}

/* Primeros problemas de la validación, legibles: «items.0.estado: …». */
function resumen(error: z.ZodError) {
  return error.issues
    .slice(0, 3)
    .map((i) => `${i.path.join('.') || '(raíz)'}: ${i.message}`)
    .join('; ')
}

export async function api<S extends z.ZodType>(path: string, { method = 'GET', body, schema, signal }: Opciones<S>): Promise<z.infer<S>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  // Solo los métodos que cambian datos llevan el token (es lo que comprueba la API).
  const token = method === 'GET' ? null : csrfActual
  if (token) headers['X-CSRF-Token'] = token

  let r: Response
  try {
    r = await fetch(url(path), {
      method,
      credentials: 'include',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    })
  } catch (e) {
    // Una consulta cancelada (TanStack Query al cambiar de vista) no es un fallo de red.
    if ((e as { name?: string } | null)?.name === 'AbortError') throw e
    throw new ApiError(0, SIN_CONEXION, 'red')
  }

  const j = await leerJson(r)
  if (r.status === 401) window.dispatchEvent(new Event(AUTH_EXPIRED))
  if (r.status === 419) window.dispatchEvent(new Event(CSRF_EXPIRED))
  if (!r.ok || !esObjeto(j) || j.ok === false) {
    const msg = esObjeto(j) && typeof j.msg === 'string' && j.msg ? j.msg : `No se ha podido contactar con el servidor (${r.status}).`
    const codigo = esObjeto(j) && typeof j.error === 'string' ? j.error : null
    throw new ApiError(r.status, msg, codigo)
  }

  const res = schema.safeParse(j)
  if (!res.success) {
    // Contrato roto entre front y API: mejor un error claro que una pantalla con datos raros.
    console.error(`Respuesta inesperada de ${method} ${path}`, res.error)
    throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (${method} ${path} → ${resumen(res.error)}).`, 'contrato')
  }
  return res.data
}
