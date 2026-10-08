import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { ApiError, guardarCsrf, leerCsrf, url, api } from '../../shared/api/client'
import {
  ClientesEquipoRespuesta,
  CsrfRespuesta,
  DatosPortal,
  EditorRespuesta,
  FacturaRespuesta,
  LoginRespuesta,
  MsgRespuesta,
  PdfRespuesta,
  SecretoRespuesta,
  SesionRespuesta,
  SolicitudRespuesta,
  TicketRespuesta,
  UrlRespuesta,
  UsuarioRespuesta,
  Vacio,
  type ContenidoEditable,
  type DatosPortal as TDatosPortal,
} from './schemas'

/* Llamadas del portal del cliente. No usa api() de shared a propósito: aquel
   avisa al AuthProvider del EQUIPO en cada 401 (y vacía su caché), y un 401
   del portal significa «sin sesión de cliente», no «el equipo ha caducado».
   Comparte con él el token CSRF (es la misma sesión de PHP) y ApiError. */
export const PORTAL_SIN_SESION = 'croilab:portal-sin-sesion'

type Metodo = 'GET' | 'POST'
async function pedir<S extends z.ZodType>(path: string, schema: S, method: Metodo = 'GET', body?: unknown, reintento = true): Promise<z.infer<S>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = method === 'GET' ? null : leerCsrf()
  if (token) headers['X-CSRF-Token'] = token
  let r: Response
  try {
    r = await fetch(url('/api/v1' + path), { method, credentials: 'include', headers, body: body === undefined ? undefined : JSON.stringify(body) })
  } catch {
    throw new ApiError(0, 'No se puede conectar con el servidor', 'red')
  }
  const j: unknown = await r.json().catch(() => null)
  const o = typeof j === 'object' && j !== null && !Array.isArray(j) ? (j as Record<string, unknown>) : null
  // Token caducado (la sesión se regeneró): se pide otro y se repite una vez.
  if (r.status === 419 && reintento && method !== 'GET') {
    await pedirCsrf()
    return pedir(path, schema, method, body, false)
  }
  if (!r.ok || !o || o.ok === false) {
    const msg = o && typeof o.msg === 'string' && o.msg ? o.msg : `No se ha podido contactar con el servidor (${r.status}).`
    const codigo = o && typeof o.error === 'string' ? o.error : null
    const campo = o && typeof o.campo === 'string' ? o.campo : null
    if (r.status === 401 && codigo === 'portal_sesion') window.dispatchEvent(new Event(PORTAL_SIN_SESION))
    throw new ApiError(r.status, msg, codigo, campo)
  }
  const res = schema.safeParse(o)
  if (!res.success) {
    console.error(`Respuesta inesperada de ${method} ${path}`, res.error)
    throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (${method} ${path}).`, 'contrato')
  }
  return res.data
}

async function pedirCsrf() {
  try {
    const s = await pedir('/portal/sesion', SesionRespuesta)
    guardarCsrf(s.csrf)
  } catch {
    // Sin servidor no hay token: la siguiente petición dirá qué pasa.
  }
}

export const qk = {
  sesion: (m: number) => ['portal', 'sesion', m] as const,
  datos: ['portal', 'datos'] as const,
  ticket: (id: number) => ['portal', 'ticket', id] as const,
  factura: (id: number) => ['portal', 'factura', id] as const,
  preview: (cli: number) => ['portal', 'equipo', cli] as const,
  previewTicket: (cli: number, id: number) => ['portal', 'equipo', cli, 'ticket', id] as const,
  previewFactura: (cli: number, id: number) => ['portal', 'equipo', cli, 'factura', id] as const,
  editor: (cli: number) => ['portal', 'editor', cli] as const,
  clientesEquipo: ['portal', 'equipo', 'clientes'] as const,
}

/* ---------- Sesión del cliente ---------- */

export function useSesionPortal(m: number) {
  return useQuery({
    queryKey: qk.sesion(m),
    queryFn: async () => {
      const s = await pedir(`/portal/sesion${m > 0 ? `?m=${m}` : ''}`, SesionRespuesta)
      guardarCsrf(s.csrf)
      return s
    },
    staleTime: 0,
  })
}

export async function entrar(usuario: string, password: string) {
  await pedirCsrf()
  const r = await pedir('/portal/auth/login', LoginRespuesta, 'POST', { usuario, password })
  guardarCsrf(r.csrf)
  return r
}

export async function salir() {
  try {
    const r = await pedir('/portal/auth/logout', CsrfRespuesta, 'POST')
    guardarCsrf(r.csrf)
  } catch {
    // Aunque falle, en este navegador se da por cerrada.
  }
}

export const urlGoogle = (m: number) => pedir(`/portal/auth/google${m > 0 ? `?m=${m}` : ''}`, UrlRespuesta)
export const pedirEnlace = async (identificador: string) => {
  await pedirCsrf()
  return pedir('/portal/auth/recuperar', MsgRespuesta, 'POST', { identificador })
}
export const comprobarEnlace = (token: string) => pedir(`/portal/auth/restablecer?token=${encodeURIComponent(token)}`, UsuarioRespuesta)
export const restablecer = async (token: string, password: string) => {
  await pedirCsrf()
  return pedir('/portal/auth/restablecer', Vacio, 'POST', { token, password })
}

/* ---------- Portal (cliente o vista previa del equipo) ---------- */

/* El origen de los datos: la sesión del cliente o la vista previa de un cliente para el equipo. */
export type Origen = { tipo: 'cliente' } | { tipo: 'equipo'; cli: number }

export function useDatosPortal(origen: Origen) {
  return useQuery<TDatosPortal>({
    queryKey: origen.tipo === 'cliente' ? qk.datos : qk.preview(origen.cli),
    queryFn: () => (origen.tipo === 'cliente' ? pedir('/portal', DatosPortal) : api(`/api/v1/portal/equipo/clientes/${origen.cli}`, { schema: DatosPortal })),
  })
}

export function useTicket(origen: Origen, id: number) {
  return useQuery({
    queryKey: origen.tipo === 'cliente' ? qk.ticket(id) : qk.previewTicket(origen.cli, id),
    queryFn: async () =>
      (origen.tipo === 'cliente' ? await pedir(`/portal/tickets/${id}`, TicketRespuesta) : await api(`/api/v1/portal/equipo/clientes/${origen.cli}/tickets/${id}`, { schema: TicketRespuesta })).ticket,
    enabled: id > 0,
  })
}

export function useFactura(origen: Origen, id: number) {
  return useQuery({
    queryKey: origen.tipo === 'cliente' ? qk.factura(id) : qk.previewFactura(origen.cli, id),
    queryFn: async () =>
      (origen.tipo === 'cliente' ? await pedir(`/portal/facturas/${id}`, FacturaRespuesta) : await api(`/api/v1/portal/equipo/clientes/${origen.cli}/facturas/${id}`, { schema: FacturaRespuesta }))
        .factura,
    enabled: id > 0,
    retry: false,
  })
}

export function pdfFactura(origen: Origen, id: number) {
  return origen.tipo === 'cliente' ? pedir(`/portal/facturas/${id}/pdf`, PdfRespuesta) : api(`/api/v1/portal/equipo/clientes/${origen.cli}/facturas/${id}/pdf`, { schema: PdfRespuesta })
}

export const verSecreto = (id: number) => pedir(`/portal/credenciales/${id}/secreto`, SecretoRespuesta, 'POST').then((r) => r.secreto)

export function useEscribir() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: { asunto: string; cuerpo: string }) => pedir('/portal/tickets', TicketRespuesta, 'POST', d),
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.datos }),
  })
}

export function useSolicitarReunion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: { motivo: string; fecha: string; franja: string }) => pedir('/portal/reuniones/solicitudes', SolicitudRespuesta, 'POST', d),
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.datos }),
  })
}

/* ---------- Equipo ---------- */

export function useClientesEquipo(activo: boolean) {
  return useQuery({ queryKey: qk.clientesEquipo, queryFn: () => api('/api/v1/portal/equipo/clientes', { schema: ClientesEquipoRespuesta }), enabled: activo })
}

export function useEditor(cli: number) {
  return useQuery({ queryKey: qk.editor(cli), queryFn: () => api(`/api/v1/portal/equipo/clientes/${cli}/editor`, { schema: EditorRespuesta }) })
}

export function useGuardarEditor(cli: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: Partial<ContenidoEditable> & { password?: string }) => api(`/api/v1/portal/equipo/clientes/${cli}/editor`, { method: 'PATCH', body: d, schema: EditorRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(qk.editor(cli), r)
      void qc.invalidateQueries({ queryKey: qk.preview(cli) })
    },
  })
}
