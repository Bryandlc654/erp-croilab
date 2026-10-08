import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { api, ApiError, AUTH_EXPIRED, conQuery, CSRF_EXPIRED, leerCsrf, url } from '../../shared/api/client'
import {
  AnalisisRespuesta,
  BorradoRespuesta,
  BuscarProyectosRespuesta,
  ClienteFacturacionRespuesta,
  ClienteMesesRespuesta,
  ClientesFacturacionRespuesta,
  DocumentoRespuesta,
  EmisoresAjustesRespuesta,
  EmisoresRespuesta,
  FacturaRespuesta,
  FacturasRespuesta,
  FichaProyectoRespuesta,
  GenerarRespuesta,
  HojaRespuesta,
  HorasRespuesta,
  HubsRespuesta,
  IdRespuesta,
  ListaRespuesta,
  MesesRespuesta,
  MovimientosRespuesta,
  NegocioRespuesta,
  NumeroRespuesta,
  PdfRespuesta,
  PersonaRespuesta,
  PersonasHorasRespuesta,
  PorClienteRespuesta,
  ProgramacionRespuesta,
  ProgramacionesRespuesta,
  ProyectoRespuesta,
  ProyectosRespuesta,
  ResumenRespuesta,
  TiposRespuesta,
  VacioRespuesta,
  VinculablesRespuesta,
  VolcadoRespuesta,
} from './schemas'

const B = '/api/v1/finanzas'

export const clavesFin = {
  todo: ['finanzas'] as const,
  emisores: ['finanzas', 'emisores'] as const,
  emisoresAjustes: ['finanzas', 'emisores', 'ajustes'] as const,
  facturas: (f: Record<string, unknown>) => ['finanzas', 'facturas', f] as const,
  factura: (id: number) => ['finanzas', 'factura', id] as const,
  hoja: (id: number) => ['finanzas', 'hoja', id] as const,
  numero: (f: Record<string, unknown>) => ['finanzas', 'numero', f] as const,
  negocio: (id: number) => ['finanzas', 'negocio', id] as const,
  hubs: ['finanzas', 'hubs'] as const,
  tipos: (e: string) => ['finanzas', 'tipos', e] as const,
  meses: (e: string, t: string) => ['finanzas', 'meses', e, t] as const,
  lista: (e: string, t: string, m: string) => ['finanzas', 'lista', e, t, m] as const,
  porCliente: ['finanzas', 'por-cliente'] as const,
  cliente: (id: number) => ['finanzas', 'por-cliente', id] as const,
  clientesFact: ['finanzas', 'clientes-facturacion'] as const,
  programaciones: ['finanzas', 'programaciones'] as const,
  movimientos: (a: string, y: number) => ['finanzas', 'movimientos', a, y] as const,
  analisis: (a: string, y: number) => ['finanzas', 'analisis', a, y] as const,
  resumen: (a: string, m: string) => ['finanzas', 'resumen', a, m] as const,
  horas: (u: number | null, m: string) => ['finanzas', 'horas', u, m] as const,
  personas: ['finanzas', 'horas', 'personas'] as const,
  proyectos: (y: string) => ['finanzas', 'proyectos', y] as const,
  proyecto: (id: number, y: string) => ['finanzas', 'proyecto', id, y] as const,
  buscarProyectos: (q: string, c: number | null) => ['finanzas', 'buscar-proyectos', q, c] as const,
  vinculables: (id: number, q: string) => ['finanzas', 'vinculables', id, q] as const,
}

export function mensajeError(e: unknown, porDefecto: string) {
  return e instanceof ApiError ? e.message : porDefecto
}

/* URL de un archivo subido (se sirve con sesión por archivo.php). */
export function urlArchivo(fn: string, descargar = false) {
  return url(`/archivo.php?d=facturas&f=${encodeURIComponent(fn)}${descargar ? '&dl=1' : ''}`)
}

/* Lo que cambia al tocar cualquier cosa de dinero: todo Finanzas. */
function useRefrescar() {
  const qc = useQueryClient()
  return () => void qc.invalidateQueries({ queryKey: clavesFin.todo })
}

/* ---------- Emisores ---------- */

export function useEmisores() {
  return useQuery({ queryKey: clavesFin.emisores, queryFn: ({ signal }) => api(`${B}/emisores`, { schema: EmisoresRespuesta, signal }), staleTime: 60_000 })
}

export function useEmisoresAjustes(enabled = true) {
  return useQuery({ queryKey: clavesFin.emisoresAjustes, queryFn: ({ signal }) => api(`${B}/emisores/ajustes`, { schema: EmisoresAjustesRespuesta, signal }), enabled })
}

export function useGuardarEmisores() {
  const qc = useQueryClient()
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: (d: unknown) => api(`${B}/emisores`, { method: 'POST', body: d, schema: EmisoresAjustesRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesFin.emisoresAjustes, r)
      refrescar()
    },
  })
}

export function useBorrarEmisor() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: (clave: string) => api(conQuery(`${B}/emisores`, { clave }), { method: 'DELETE', schema: EmisoresAjustesRespuesta }),
    onSuccess: refrescar,
  })
}

/* ---------- Facturas ---------- */

export type FiltrosFacturas = { emisor?: string; client_id?: number; estado?: string; mes?: string; q?: string; project_id?: number; tipo?: string; limit?: number }

export function useFacturas(f: FiltrosFacturas, enabled = true) {
  return useQuery({
    queryKey: clavesFin.facturas(f),
    queryFn: ({ signal }) => api(conQuery(`${B}/facturas`, f), { schema: FacturasRespuesta, signal }),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useFactura(id: number) {
  return useQuery({
    queryKey: clavesFin.factura(id),
    queryFn: ({ signal }) => api(`${B}/facturas/${id}`, { schema: FacturaRespuesta, signal }).then((r) => r.factura),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useHoja(id: number) {
  return useQuery({
    queryKey: clavesFin.hoja(id),
    queryFn: ({ signal }) => api(`${B}/facturas/${id}/hoja`, { schema: HojaRespuesta, signal }).then((r) => r.hoja),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useSiguienteNumero(f: { emisor: string; serie: string; fecha: string; tipo: string }, enabled: boolean) {
  return useQuery({
    queryKey: clavesFin.numero(f),
    queryFn: ({ signal }) => api(conQuery(`${B}/facturas/siguiente-numero`, f), { schema: NumeroRespuesta, signal }),
    select: (r) => r.numero,
    enabled: enabled && !!f.emisor,
    placeholderData: keepPreviousData,
    retry: false,
  })
}

export function useDesdeNegocio(dealId: number) {
  return useQuery({
    queryKey: clavesFin.negocio(dealId),
    queryFn: ({ signal }) => api(`${B}/facturas/desde-negocio/${dealId}`, { schema: NegocioRespuesta, signal }),
    enabled: dealId > 0,
    retry: false,
  })
}

export function useGuardarFactura() {
  const qc = useQueryClient()
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, datos }: { id: number | null; datos: unknown }) =>
      id ? api(`${B}/facturas/${id}`, { method: 'PATCH', body: datos, schema: FacturaRespuesta }) : api(`${B}/facturas`, { method: 'POST', body: datos, schema: FacturaRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesFin.factura(r.factura.id), r.factura)
      refrescar()
    },
  })
}

/* Acciones sobre una factura (emitir, estado, duplicar, rectificar, anular, proyecto). */
export function useAccionFactura() {
  const qc = useQueryClient()
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, accion, datos }: { id: number; accion: 'emitir' | 'estado' | 'duplicar' | 'rectificar' | 'anular' | 'proyecto'; datos?: unknown }) =>
      api(`${B}/facturas/${id}/${accion}`, { method: accion === 'proyecto' ? 'PATCH' : 'POST', body: datos ?? {}, schema: FacturaRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesFin.factura(r.factura.id), r.factura)
      refrescar()
    },
  })
}

export function useBorrarFactura() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: (id: number) => api(`${B}/facturas/${id}`, { method: 'DELETE', schema: BorradoRespuesta }),
    onSuccess: refrescar,
  })
}

/* «Deshacer» del borrado de un borrador o de un documento subido. */
export function useRestaurar() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: (papeleraId: number) => api(`${B}/papelera/${papeleraId}/restaurar`, { method: 'POST', schema: IdRespuesta }),
    onSuccess: refrescar,
  })
}

export async function pedirPdf(id: number) {
  return api(`${B}/facturas/${id}/pdf`, { schema: PdfRespuesta })
}

/* ---------- Explorador ---------- */

export function useHubs() {
  return useQuery({ queryKey: clavesFin.hubs, queryFn: ({ signal }) => api(`${B}/explorador/hubs`, { schema: HubsRespuesta, signal }) })
}

export function useTipos(emisor: string) {
  return useQuery({ queryKey: clavesFin.tipos(emisor), queryFn: ({ signal }) => api(conQuery(`${B}/explorador/tipos`, { emisor }), { schema: TiposRespuesta, signal }), enabled: !!emisor })
}

export function useMeses(emisor: string, tipo: string) {
  return useQuery({
    queryKey: clavesFin.meses(emisor, tipo),
    queryFn: ({ signal }) => api(conQuery(`${B}/explorador/meses`, { emisor, tipo }), { schema: MesesRespuesta, signal }),
    enabled: !!emisor && !!tipo,
  })
}

export function useLista(emisor: string, tipo: string, mes: string) {
  return useQuery({
    queryKey: clavesFin.lista(emisor, tipo, mes),
    queryFn: ({ signal }) => api(conQuery(`${B}/explorador/lista`, { emisor, tipo, mes }), { schema: ListaRespuesta, signal }),
    enabled: !!emisor && !!tipo && !!mes,
  })
}

export function usePorCliente() {
  return useQuery({ queryKey: clavesFin.porCliente, queryFn: ({ signal }) => api(`${B}/por-cliente`, { schema: PorClienteRespuesta, signal }) })
}

export function useClienteMeses(id: number) {
  return useQuery({
    queryKey: clavesFin.cliente(id),
    queryFn: ({ signal }) => api(`${B}/por-cliente/${id}`, { schema: ClienteMesesRespuesta, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useClientesFacturacion(enabled = true) {
  return useQuery({ queryKey: clavesFin.clientesFact, queryFn: ({ signal }) => api(`${B}/clientes-facturacion`, { schema: ClientesFacturacionRespuesta, signal }), enabled, staleTime: 30_000 })
}

export function useGuardarClienteFacturacion() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, datos }: { id: number; datos: unknown }) => api(`${B}/clientes-facturacion/${id}`, { method: 'PATCH', body: datos, schema: ClienteFacturacionRespuesta }),
    onSuccess: refrescar,
  })
}

/* ---------- Documentos subidos (multipart) ---------- */

/* Como api(), pero con FormData: el navegador pone el multipart con su frontera. */
async function pedirForm<S extends z.ZodType>(path: string, form: FormData, schema: S): Promise<z.infer<S>> {
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
    throw new ApiError(r.status, typeof o.msg === 'string' && o.msg ? o.msg : `No se ha podido contactar con el servidor (${r.status}).`, typeof o.error === 'string' ? o.error : null, typeof o.campo === 'string' ? o.campo : null)
  }
  const res = schema.safeParse(j)
  if (!res.success) throw new ApiError(r.status, 'La respuesta del servidor no tiene el formato esperado.', 'contrato')
  return res.data
}

export function useGuardarDocumento() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, form }: { id: number | null; form: FormData }) => pedirForm(id ? `${B}/documentos/${id}` : `${B}/documentos`, form, DocumentoRespuesta),
    onSuccess: refrescar,
  })
}

export function useBorrarDocumento() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: (id: number) => api(`${B}/documentos/${id}`, { method: 'DELETE', schema: BorradoRespuesta }),
    onSuccess: refrescar,
  })
}

/* ---------- Programaciones ---------- */

export function useProgramaciones() {
  return useQuery({ queryKey: clavesFin.programaciones, queryFn: ({ signal }) => api(`${B}/programaciones`, { schema: ProgramacionesRespuesta, signal }) })
}

export function useGuardarProgramacion() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, datos }: { id: number | null; datos: unknown }) =>
      id ? api(`${B}/programaciones/${id}`, { method: 'PATCH', body: datos, schema: ProgramacionRespuesta }) : api(`${B}/programaciones`, { method: 'POST', body: datos, schema: ProgramacionRespuesta }),
    onSuccess: refrescar,
  })
}

export function useAccionProgramacion() {
  const refrescar = useRefrescar()
  return useMutation({
    mutationFn: ({ id, accion }: { id: number; accion: 'pausar' | 'activar' | 'borrar' }) =>
      accion === 'borrar'
        ? api(`${B}/programaciones/${id}`, { method: 'DELETE', schema: VacioRespuesta })
        : api(`${B}/programaciones/${id}/${accion}`, { method: 'POST', schema: ProgramacionRespuesta }),
    onSuccess: refrescar,
  })
}

export function useGenerar() {
  const refrescar = useRefrescar()
  return useMutation({ mutationFn: () => api(`${B}/programaciones/generar`, { method: 'POST', schema: GenerarRespuesta }), onSuccess: refrescar })
}

/* ---------- Contabilidad y resumen ---------- */

export function useMovimientos(ambito: string, anio: number) {
  return useQuery({
    queryKey: clavesFin.movimientos(ambito, anio),
    queryFn: ({ signal }) => api(conQuery(`${B}/contabilidad/movimientos`, { ambito, anio }), { schema: MovimientosRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useAnalisis(ambito: string, anio: number) {
  return useQuery({
    queryKey: clavesFin.analisis(ambito, anio),
    queryFn: ({ signal }) => api(conQuery(`${B}/contabilidad/analisis`, { ambito, anio }), { schema: AnalisisRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useResumen(ambito: string, mes: string) {
  return useQuery({
    queryKey: clavesFin.resumen(ambito, mes),
    queryFn: ({ signal }) => api(conQuery(`${B}/resumen`, { ambito, mes }), { schema: ResumenRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

/* ---------- Horas ---------- */

export function usePersonasHoras() {
  return useQuery({ queryKey: clavesFin.personas, queryFn: ({ signal }) => api(`${B}/horas/personas`, { schema: PersonasHorasRespuesta, signal }) })
}

export function useHoras(adminId: number | null, mes: string) {
  return useQuery({
    queryKey: clavesFin.horas(adminId, mes),
    queryFn: ({ signal }) => api(conQuery(`${B}/horas`, { admin_id: adminId, mes }), { schema: HorasRespuesta, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useAccionHoras() {
  const refrescar = useRefrescar()
  return {
    extra: useMutation({ mutationFn: (d: unknown) => api(`${B}/horas/extras`, { method: 'POST', body: d, schema: IdRespuesta }), onSuccess: refrescar }),
    borrar: useMutation({ mutationFn: (id: number) => api(`${B}/horas/${id}`, { method: 'DELETE', schema: VacioRespuesta }), onSuccess: refrescar }),
    tarifa: useMutation({
      mutationFn: ({ id, datos }: { id: number; datos: unknown }) => api(`${B}/horas/personas/${id}/tarifa`, { method: 'PATCH', body: datos, schema: PersonaRespuesta }),
      onSuccess: refrescar,
    }),
    volcar: useMutation({ mutationFn: (d: { admin_id: number; mes: string }) => api(`${B}/horas/volcar`, { method: 'POST', body: d, schema: VolcadoRespuesta }), onSuccess: refrescar }),
  }
}

/* ---------- Proyectos ---------- */

export function useProyectos(anio: string) {
  return useQuery({ queryKey: clavesFin.proyectos(anio), queryFn: ({ signal }) => api(conQuery(`${B}/proyectos`, { anio }), { schema: ProyectosRespuesta, signal }), placeholderData: keepPreviousData })
}

export function useFichaProyecto(id: number, anio: string) {
  return useQuery({
    queryKey: clavesFin.proyecto(id, anio),
    queryFn: ({ signal }) => api(conQuery(`${B}/proyectos/${id}`, { anio }), { schema: FichaProyectoRespuesta, signal }),
    enabled: id > 0,
    placeholderData: keepPreviousData,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useBuscarProyectos(q: string, clientId: number | null, enabled: boolean) {
  return useQuery({
    queryKey: clavesFin.buscarProyectos(q, clientId),
    queryFn: ({ signal }) => api(conQuery(`${B}/proyectos/buscar`, { q, client_id: clientId }), { schema: BuscarProyectosRespuesta, signal }),
    enabled,
    placeholderData: keepPreviousData,
  })
}

export function useVinculables(id: number, q: string, enabled: boolean) {
  return useQuery({
    queryKey: clavesFin.vinculables(id, q),
    queryFn: ({ signal }) => api(conQuery(`${B}/proyectos/${id}/facturas-vinculables`, { q }), { schema: VinculablesRespuesta, signal }),
    enabled,
    placeholderData: keepPreviousData,
  })
}

export function useAccionProyecto() {
  const refrescar = useRefrescar()
  return {
    crear: useMutation({ mutationFn: (d: unknown) => api(`${B}/proyectos`, { method: 'POST', body: d, schema: ProyectoRespuesta }), onSuccess: refrescar }),
    actualizar: useMutation({
      mutationFn: ({ id, datos }: { id: number; datos: unknown }) => api(`${B}/proyectos/${id}`, { method: 'PATCH', body: datos, schema: ProyectoRespuesta }),
      onSuccess: refrescar,
    }),
    borrar: useMutation({ mutationFn: (id: number) => api(`${B}/proyectos/${id}`, { method: 'DELETE', schema: VacioRespuesta }), onSuccess: refrescar }),
    movimiento: useMutation({
      mutationFn: ({ id, datos }: { id: number; datos: unknown }) => api(`${B}/proyectos/${id}/movimientos`, { method: 'POST', body: datos, schema: IdRespuesta }),
      onSuccess: refrescar,
    }),
    desvincularMov: useMutation({
      mutationFn: ({ id, acc }: { id: number; acc: number }) => api(`${B}/proyectos/${id}/movimientos/${acc}`, { method: 'DELETE', schema: VacioRespuesta }),
      onSuccess: refrescar,
    }),
    factura: useMutation({
      mutationFn: ({ id, inv, vincular }: { id: number; inv: number; vincular: boolean }) =>
        api(`${B}/proyectos/${id}/facturas/${inv}`, { method: vincular ? 'POST' : 'DELETE', schema: VacioRespuesta }),
      onSuccess: refrescar,
    }),
  }
}
