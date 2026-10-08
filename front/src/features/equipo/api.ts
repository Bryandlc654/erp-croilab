import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { api, ApiError, AUTH_EXPIRED, CSRF_EXPIRED, conQuery, leerCsrf, url } from '../../shared/api/client'
import { clavesNav } from '../nav/api'
import {
  AgenciaRespuesta,
  AvisosRespuesta,
  BovedaClientesRespuesta,
  ContactoRespuesta,
  CredencialesRespuesta,
  CuentaRespuesta,
  FichaRespuesta,
  IntegracionesRespuesta,
  InvitacionesRespuesta,
  MetricasRespuesta,
  MiembrosRespuesta,
  PerfilRespuesta,
  ReglasRespuesta,
  RolesRespuesta,
  VideosRespuesta,
} from './schemas'

/* Claves de TanStack Query del módulo: todas empiezan por 'equipo'. */
export const clavesEquipo = {
  todo: ['equipo-mod'] as const,
  miembros: (bajas: boolean) => ['equipo-mod', 'miembros', bajas] as const,
  miembro: (id: number) => ['equipo-mod', 'miembro', id] as const,
  invitaciones: ['equipo-mod', 'invitaciones'] as const,
  perfil: (id: number) => ['equipo-mod', 'perfil', id] as const,
  cuenta: ['equipo-mod', 'cuenta'] as const,
  avisos: ['equipo-mod', 'avisos'] as const,
  roles: ['equipo-mod', 'roles'] as const,
  agencia: ['equipo-mod', 'agencia'] as const,
  contacto: ['equipo-mod', 'contacto'] as const,
  videos: ['equipo-mod', 'videos'] as const,
  reglas: ['equipo-mod', 'reglas'] as const,
  metricas: ['equipo-mod', 'metricas'] as const,
  integraciones: ['equipo-mod', 'integraciones'] as const,
  bovedaClientes: ['equipo-mod', 'boveda'] as const,
  credenciales: (cli: number) => ['equipo-mod', 'boveda', cli] as const,
}

export function mensaje(e: unknown, porDefecto = 'No se ha podido guardar.') {
  return e instanceof ApiError ? e.message : porDefecto
}

/* Campo al que apunta un 422 de la API ({campo}), para marcarlo en el formulario. */
export function campoDeError(e: unknown): string | null {
  return e instanceof ApiErrorConCampo ? e.campo : null
}

/* ApiError no conserva `campo`; las peticiones de este módulo pasan por aquí
   para no perderlo. */
export class ApiErrorConCampo extends ApiError {
  readonly campo: string | null
  readonly zona: string | null
  constructor(status: number, msg: string, codigo: string | null, campo: string | null, zona: string | null) {
    super(status, msg, codigo)
    this.campo = campo
    this.zona = zona
  }
}

type Metodo = 'GET' | 'POST' | 'PATCH' | 'DELETE'

/* Como api() de shared, pero conservando `campo` (422) y `zona` (403 reauth)
   del error, y admitiendo FormData para las subidas (el navegador pone el
   Content-Type multipart con su frontera). */
export async function pedir<S extends z.ZodType>(path: string, opts: { method?: Metodo; body?: unknown; form?: FormData; schema: S; signal?: AbortSignal }): Promise<z.infer<S>> {
  const { method = 'GET', body, form, schema, signal } = opts
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined && !form) headers['Content-Type'] = 'application/json'
  const token = method === 'GET' ? null : leerCsrf()
  if (token) headers['X-CSRF-Token'] = token
  let r: Response
  try {
    r = await fetch(url(path), { method, credentials: 'include', headers, body: form ?? (body === undefined ? undefined : JSON.stringify(body)), signal })
  } catch (e) {
    if ((e as { name?: string } | null)?.name === 'AbortError') throw e
    throw new ApiError(0, 'No se puede conectar con el servidor', 'red')
  }
  // Una respuesta que no es JSON (500 con HTML, proxy) se trata como fallo.
  const j: unknown = await r.json().catch(() => null)
  if (r.status === 401) window.dispatchEvent(new Event(AUTH_EXPIRED))
  if (r.status === 419) window.dispatchEvent(new Event(CSRF_EXPIRED))
  const o = typeof j === 'object' && j !== null && !Array.isArray(j) ? (j as Record<string, unknown>) : {}
  if (!r.ok || o.ok === false) {
    throw new ApiErrorConCampo(
      r.status,
      typeof o.msg === 'string' && o.msg ? o.msg : `No se ha podido contactar con el servidor (${r.status}).`,
      typeof o.error === 'string' ? o.error : null,
      typeof o.campo === 'string' ? o.campo : null,
      typeof o.zona === 'string' ? o.zona : null,
    )
  }
  const res = schema.safeParse(j)
  if (!res.success) {
    console.error(`Respuesta inesperada de ${method} ${path}`, res.error)
    throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (${method} ${path}).`, 'contrato')
  }
  return res.data
}

/* ---------- Consultas ---------- */

export function useMiembros(bajas = false) {
  return useQuery({
    queryKey: clavesEquipo.miembros(bajas),
    queryFn: ({ signal }) => api(conQuery('/api/v1/equipo/miembros', { bajas: bajas ? 1 : undefined }), { schema: MiembrosRespuesta, signal }),
  })
}

export function useMiembro(id: number) {
  return useQuery({
    queryKey: clavesEquipo.miembro(id),
    queryFn: ({ signal }) => api(`/api/v1/equipo/miembros/${id}`, { schema: FichaRespuesta, signal }),
    enabled: id > 0,
    retry: false,
  })
}

export function useInvitaciones() {
  return useQuery({
    queryKey: clavesEquipo.invitaciones,
    queryFn: ({ signal }) => api('/api/v1/equipo/invitaciones', { schema: InvitacionesRespuesta, signal }),
  })
}

export function usePerfil(id: number) {
  return useQuery({
    queryKey: clavesEquipo.perfil(id),
    queryFn: ({ signal }) => api(`/api/v1/perfiles/${id}`, { schema: PerfilRespuesta, signal }),
    enabled: id > 0,
    retry: false,
  })
}

export function useCuenta() {
  return useQuery({ queryKey: clavesEquipo.cuenta, queryFn: ({ signal }) => api('/api/v1/me/cuenta', { schema: CuentaRespuesta, signal }) })
}

export function useAvisos() {
  return useQuery({ queryKey: clavesEquipo.avisos, queryFn: ({ signal }) => api('/api/v1/me/avisos', { schema: AvisosRespuesta, signal }) })
}

export function useRoles() {
  return useQuery({ queryKey: clavesEquipo.roles, queryFn: ({ signal }) => api('/api/v1/roles', { schema: RolesRespuesta, signal }) })
}

export function useAgencia() {
  return useQuery({ queryKey: clavesEquipo.agencia, queryFn: ({ signal }) => api('/api/v1/ajustes/agencia', { schema: AgenciaRespuesta, signal }) })
}

export function useContacto() {
  return useQuery({ queryKey: clavesEquipo.contacto, queryFn: ({ signal }) => api('/api/v1/ajustes/portal/contacto', { schema: ContactoRespuesta, signal }) })
}

export function useVideos() {
  return useQuery({ queryKey: clavesEquipo.videos, queryFn: ({ signal }) => api('/api/v1/ajustes/portal/videos', { schema: VideosRespuesta, signal }) })
}

export function useReglas() {
  return useQuery({ queryKey: clavesEquipo.reglas, queryFn: ({ signal }) => api('/api/v1/ajustes/automatizaciones', { schema: ReglasRespuesta, signal }) })
}

export function useMetricas() {
  return useQuery({ queryKey: clavesEquipo.metricas, queryFn: ({ signal }) => api('/api/v1/ajustes/metricas', { schema: MetricasRespuesta, signal }) })
}

export function useIntegraciones() {
  return useQuery({ queryKey: clavesEquipo.integraciones, queryFn: ({ signal }) => api('/api/v1/integraciones', { schema: IntegracionesRespuesta, signal }) })
}

export function useBovedaClientes() {
  return useQuery({ queryKey: clavesEquipo.bovedaClientes, queryFn: ({ signal }) => api('/api/v1/credenciales/clientes', { schema: BovedaClientesRespuesta, signal }) })
}

export function useCredenciales(cli: number) {
  return useQuery({
    queryKey: clavesEquipo.credenciales(cli),
    queryFn: ({ signal }) => api(`/api/v1/credenciales/clientes/${cli}`, { schema: CredencialesRespuesta, signal }),
    enabled: cli > 0,
    retry: false,
  })
}

/* Mutación genérica del módulo: al terminar refresca lo que se indique (y el
   menú y el equipo de /v1/equipo, que pintan nombres y fotos). */
export function useAccion<V, R>(fn: (v: V) => Promise<R>, invalidar: readonly (readonly unknown[])[] = [clavesEquipo.todo]) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      for (const k of invalidar) void qc.invalidateQueries({ queryKey: k })
      void qc.invalidateQueries({ queryKey: clavesNav.equipo })
    },
  })
}
