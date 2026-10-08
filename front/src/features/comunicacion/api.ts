import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { api, ApiError, AUTH_EXPIRED, conQuery, CSRF_EXPIRED, leerCsrf, url } from '../../shared/api/client'
import { clavesNav } from '../nav/api'
import {
  AgendarRespuesta,
  CalendarioRespuesta,
  ClientesSoporteRespuesta,
  ContactosRespuesta,
  CorreosRespuesta,
  DestinatarioRespuesta,
  ReunionesRespuesta,
  SalasRespuesta,
  TicketDetalleRespuesta,
  TicketsRespuesta,
  UrlRespuesta,
} from './schemas'

/* Claves de TanStack Query del módulo: todas empiezan por 'comunicacion'. */
export const clavesCom = {
  todo: ['comunicacion'] as const,
  salas: ['comunicacion', 'salas'] as const,
  historial: (sala: number) => ['comunicacion', 'historial', sala] as const,
  tickets: (estado: string, cliente: number) => ['comunicacion', 'tickets', estado, cliente] as const,
  ticketsTodo: ['comunicacion', 'tickets'] as const,
  ticket: (id: number) => ['comunicacion', 'ticket', id] as const,
  clientes: ['comunicacion', 'clientes'] as const,
  calendario: (desde: string, hasta: string, equipo: string) => ['comunicacion', 'calendario', desde, hasta, equipo] as const,
  calendarioTodo: ['comunicacion', 'calendario'] as const,
  correos: ['comunicacion', 'correos'] as const,
  reuniones: (vista: string) => ['comunicacion', 'reuniones', vista] as const,
  reunionesTodo: ['comunicacion', 'reuniones'] as const,
  contactos: (q: string) => ['comunicacion', 'contactos', q] as const,
  destinatario: (cli: number, contacto: number) => ['comunicacion', 'destinatario', cli, contacto] as const,
}

export function mensaje(e: unknown, porDefecto = 'No se ha podido guardar.') {
  return e instanceof ApiError ? e.message : porDefecto
}

/* Como api() de shared, pero con FormData (mensajes con adjuntos): el
   navegador pone el Content-Type multipart. Mismo CSRF y manejo de errores. */
export async function enviarFormulario<S extends z.ZodType>(path: string, form: FormData, schema: S): Promise<z.infer<S>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = leerCsrf()
  if (token) headers['X-CSRF-Token'] = token
  let r: Response
  try {
    r = await fetch(url(path), { method: 'POST', credentials: 'include', headers, body: form })
  } catch {
    throw new ApiError(0, 'No se puede conectar con el servidor', 'red')
  }
  const j: unknown = await r.json().catch(() => null)
  if (r.status === 401) window.dispatchEvent(new Event(AUTH_EXPIRED))
  if (r.status === 419) window.dispatchEvent(new Event(CSRF_EXPIRED))
  const o = typeof j === 'object' && j !== null && !Array.isArray(j) ? (j as Record<string, unknown>) : {}
  if (!r.ok || o.ok === false) {
    const msg = typeof o.msg === 'string' && o.msg ? o.msg : r.status === 413 ? 'Los archivos pesan demasiado.' : `No se ha podido contactar con el servidor (${r.status}).`
    throw new ApiError(r.status, msg, typeof o.error === 'string' ? o.error : null, typeof o.campo === 'string' ? o.campo : null)
  }
  const res = schema.safeParse(j)
  if (!res.success) throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (POST ${path}).`, 'contrato')
  return res.data
}

/* ---------- Chat ---------- */

export function useSalas() {
  return useQuery({
    queryKey: clavesCom.salas,
    queryFn: ({ signal }) => api(conQuery('/api/v1/chat/salas', { activo: document.visibilityState === 'visible' ? 1 : 0 }), { schema: SalasRespuesta, signal }),
    // La lista se refresca con el sondeo de la sala abierta (no_leidos); esto es el respaldo.
    refetchInterval: 15_000,
  })
}

/* ---------- Soporte ---------- */

export function useTickets(estado: string, cliente: number) {
  return useQuery({
    queryKey: clavesCom.tickets(estado, cliente),
    queryFn: ({ signal }) => api(conQuery('/api/v1/soporte/tickets', { estado, cliente: cliente || undefined }), { schema: TicketsRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useTicket(id: number) {
  return useQuery({
    queryKey: clavesCom.ticket(id),
    queryFn: ({ signal }) => api(`/api/v1/soporte/tickets/${id}`, { schema: TicketDetalleRespuesta, signal }),
    enabled: id > 0,
    retry: false,
  })
}

export function useClientesSoporte(enabled = true) {
  return useQuery({
    queryKey: clavesCom.clientes,
    queryFn: ({ signal }) => api('/api/v1/soporte/clientes', { schema: ClientesSoporteRespuesta, signal }),
    select: (d) => d.items,
    staleTime: 5 * 60_000,
    enabled,
  })
}

/* Tras cambiar un ticket: listas, detalle y el contador de avisos. */
export function useInvalidarSoporte() {
  const qc = useQueryClient()
  return (id?: number) => {
    void qc.invalidateQueries({ queryKey: clavesCom.ticketsTodo })
    if (id) void qc.invalidateQueries({ queryKey: clavesCom.ticket(id) })
    void qc.invalidateQueries({ queryKey: clavesNav.nav })
  }
}

/* ---------- Calendario y reuniones ---------- */

export function useCalendario(desde: string, hasta: string, equipo: number[]) {
  const eq = equipo.join(',')
  return useQuery({
    queryKey: clavesCom.calendario(desde, hasta, eq),
    queryFn: ({ signal }) => api(conQuery('/api/v1/calendario', { desde, hasta, equipo: eq }), { schema: CalendarioRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useCorreos(enabled: boolean) {
  return useQuery({
    queryKey: clavesCom.correos,
    queryFn: ({ signal }) => api('/api/v1/calendario/correos', { schema: CorreosRespuesta, signal }),
    select: (d) => d.items,
    staleTime: 5 * 60_000,
    enabled,
  })
}

export function useReuniones(vista: string) {
  return useQuery({
    queryKey: clavesCom.reuniones(vista),
    queryFn: ({ signal }) => api(conQuery('/api/v1/reuniones', { vista }), { schema: ReunionesRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useContactos(q: string, enabled: boolean) {
  return useQuery({
    queryKey: clavesCom.contactos(q),
    queryFn: ({ signal }) => api(conQuery('/api/v1/reuniones/contactos', { q }), { schema: ContactosRespuesta, signal }),
    select: (d) => d.grupos,
    enabled,
    placeholderData: keepPreviousData,
  })
}

export function useDestinatario(cli: number, contacto: number, enabled = true) {
  return useQuery({
    queryKey: clavesCom.destinatario(cli, contacto),
    queryFn: ({ signal }) => api(conQuery('/api/v1/reuniones/destinatario', { cli: cli || undefined, contacto: contacto || undefined }), { schema: DestinatarioRespuesta, signal }),
    select: (d) => d.destinatario,
    enabled,
    retry: false,
  })
}

/* Datos de «Agendar»/«Crear reunión» (POST /v1/reuniones). */
export type DatosAgendar = {
  titulo: string
  fecha: string
  hora: string
  duracion?: number
  hora_fin?: string
  invitados: string
  meet: boolean
  gemini: boolean
  notificar: boolean
  recordar: string
  descripcion?: string
  contact_id?: number | null
  req_id?: number | null
}

export function useAgendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: DatosAgendar) => api('/api/v1/reuniones', { method: 'POST', body: d, schema: AgendarRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })
      void qc.invalidateQueries({ queryKey: clavesCom.calendarioTodo })
      // El CRM enseña las reuniones del contacto: si tiene sus claves, que se refresquen.
      void qc.invalidateQueries({ queryKey: ['crm'] })
    },
  })
}

/* URL de Google para conectar MI calendario (endpoint de Equipo). */
export async function urlConectarGoogle(volver: string) {
  return (await api(conQuery('/api/v1/integraciones/google-calendar/conectar', { volver }), { schema: UrlRespuesta })).url
}
