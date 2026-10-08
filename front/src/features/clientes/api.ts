import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, conQuery } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { useToast } from '../../shared/ui/useToast'
import { clavesNav } from '../nav/api'
import { pedir } from '../equipo/api'
import {
  AgenciaRespuesta,
  AgenciasRespuesta,
  AvanzadoRespuesta,
  BorradoRespuesta,
  ClienteRespuesta,
  ClientesPagina,
  ClientesTablaPagina,
  CreadoRespuesta,
  DatosRespuesta,
  DuplicadoRespuesta,
  EventosRespuesta,
  FichaSchema,
  GoogleSchema,
  GoogleSyncRespuesta,
  PasswordRespuesta,
  RestauradoRespuesta,
  SecretoRespuesta,
  ServiciosGuardadosRespuesta,
  ServiciosRespuesta,
  TipoCreadoRespuesta,
  TipoRespuesta,
  TiposRespuesta,
  VacioRespuesta,
  type Agencia,
  type ClienteFila,
} from './schemas'

export const clavesClientes = {
  todo: ['clientes'] as const,
  busqueda: (q: string) => ['clientes', 'busqueda', q] as const,
  seccion: (activo: boolean) => ['clientes', 'seccion', activo] as const,
  detalle: (id: number) => ['clientes', 'detalle', id] as const,
  tabla: ['clientes', 'tabla'] as const,
  ficha: (id: number) => ['clientes', 'ficha', id] as const,
  datos: (id: number) => ['clientes', 'datos', id] as const,
  tipos: ['clientes', 'tipos'] as const,
  tipo: (id: number) => ['clientes', 'tipos', id] as const,
  servicios: ['clientes', 'servicios'] as const,
  agencias: ['clientes', 'agencias'] as const,
  google: (id: number) => ['clientes', 'google', id] as const,
  avanzado: (id: number) => ['clientes', 'avanzado', id] as const,
}

const LIMITE_BUSQUEDA = 20
const LIMITE_SECCION = 50
const LIMITE_TABLA = 200

export function mensajeError(e: unknown, porDefecto: string) {
  return e instanceof ApiError ? e.message : porDefecto
}

/* Buscador «Ir a un cliente»: el servidor filtra por prefijo del nombre.
   Mientras llega la respuesta nueva se siguen viendo los resultados anteriores. */
export function useBuscarClientes(q: string) {
  const texto = q.trim()
  return useQuery({
    queryKey: clavesClientes.busqueda(texto),
    queryFn: ({ signal }) => api(conQuery('/api/v1/clientes', { q: texto, limit: LIMITE_BUSQUEDA }), { schema: ClientesPagina, signal }),
    placeholderData: keepPreviousData,
  })
}

/* Una sección de la barra lateral (activos / no activos), con sus listas.
   Solo se pide cuando la sección está desplegada, de 50 en 50. */
export function useClientesSeccion(activo: boolean, enabled: boolean) {
  return useInfiniteQuery({
    queryKey: clavesClientes.seccion(activo),
    queryFn: ({ pageParam, signal }) =>
      api(conQuery('/api/v1/clientes', { activo, con_listas: 1, limit: LIMITE_SECCION, offset: pageParam }), { schema: ClientesPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    enabled,
  })
}

/* Un cliente con sus listas. id 0 = ninguno (no se pide nada). */
export function useCliente(id: number) {
  return useQuery({
    queryKey: clavesClientes.detalle(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/${id}`, { schema: ClienteRespuesta, signal }),
    enabled: id > 0,
  })
}

/* ---------- Listado ---------- */

/* Todos los clientes visibles con su actividad. El listado filtra en el
   navegador (segmentado, buscador y tipo), como el antiguo: se piden todas las
   páginas de 200 de una vez. */
export function useClientesTabla() {
  return useQuery({
    queryKey: clavesClientes.tabla,
    queryFn: async ({ signal }) => {
      const items: ClienteFila[] = []
      for (let offset = 0; ; ) {
        const p = await api(conQuery('/api/v1/clientes', { actividad: 1, limit: LIMITE_TABLA, offset }), { schema: ClientesTablaPagina, signal })
        items.push(...p.items)
        const sig = siguienteOffset(p)
        if (sig === undefined) return items
        offset = sig
      }
    },
  })
}

/* ---------- Ficha y formulario ---------- */

export function useFicha(id: number) {
  return useQuery({
    queryKey: clavesClientes.ficha(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/${id}/ficha`, { schema: FichaSchema, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useDatosCliente(id: number) {
  return useQuery({
    queryKey: clavesClientes.datos(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/${id}/datos`, { schema: DatosRespuesta, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

/* Revela una contraseña de la bóveda (no viaja en la ficha). */
export function pedirSecreto(cliente: number, credencial: number) {
  return api(`/api/v1/clientes/${cliente}/ficha/secreto/${credencial}`, { schema: SecretoRespuesta }).then((r) => r.secreto)
}

/* Lo que cambia al tocar un cliente: listados, ficha, barra lateral y contadores. */
function useRefrescarClientes() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    void qc.invalidateQueries({ queryKey: clavesNav.nav })
  }
}

export type CuerpoCliente = Record<string, unknown>

export function useCrearCliente() {
  const refrescar = useRefrescarClientes()
  return useMutation({
    mutationFn: (datos: CuerpoCliente) => api('/api/v1/clientes', { method: 'POST', body: datos, schema: CreadoRespuesta }),
    onSuccess: refrescar,
  })
}

export function useGuardarCliente(id: number) {
  const qc = useQueryClient()
  const refrescar = useRefrescarClientes()
  return useMutation({
    mutationFn: (datos: CuerpoCliente) => api(`/api/v1/clientes/${id}`, { method: 'PATCH', body: datos, schema: DatosRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesClientes.datos(id), r)
      refrescar()
    },
  })
}

export function useRestaurarCliente() {
  const refrescar = useRefrescarClientes()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (papeleraId: number) => api(`/api/v1/clientes/papelera/${papeleraId}/restaurar`, { method: 'POST', schema: RestauradoRespuesta }),
    onSuccess: () => {
      refrescar()
      aviso('Cliente restaurado.')
    },
    onError: (e) => aviso(mensajeError(e, 'No se ha podido deshacer.'), { tipo: 'error' }),
  })
}

/* A la papelera: el aviso ofrece «Deshacer» unos segundos. */
export function useBorrarCliente() {
  const refrescar = useRefrescarClientes()
  const { aviso } = useToast()
  const restaurar = useRestaurarCliente()
  return useMutation({
    mutationFn: (c: { id: number; name: string }) => api(`/api/v1/clientes/${c.id}`, { method: 'DELETE', schema: BorradoRespuesta }),
    onSuccess: ({ papelera_id }, c) => {
      refrescar()
      aviso(`Cliente «${c.name}» eliminado`, { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
    },
    onError: (e) => aviso(mensajeError(e, 'No se ha podido borrar.'), { tipo: 'error' }),
  })
}

export function useDuplicarCliente() {
  const refrescar = useRefrescarClientes()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (id: number) => api(`/api/v1/clientes/${id}/duplicar`, { method: 'POST', schema: DuplicadoRespuesta }),
    onSuccess: refrescar,
    onError: (e) => aviso(mensajeError(e, 'No se ha podido duplicar.'), { tipo: 'error' }),
  })
}

export function useRestablecerPassword(id: number) {
  const { aviso } = useToast()
  return useMutation({
    mutationFn: () => api(`/api/v1/clientes/${id}/password`, { method: 'POST', schema: PasswordRespuesta }),
    onError: (e) => aviso(mensajeError(e, 'No se ha podido restablecer la contraseña.'), { tipo: 'error' }),
  })
}

/* ---------- Tipos ---------- */

export function useTipos(enabled = true) {
  return useQuery({
    queryKey: clavesClientes.tipos,
    queryFn: ({ signal }) => api('/api/v1/clientes/tipos', { schema: TiposRespuesta, signal }).then((r) => r.items),
    enabled,
  })
}

export function useTipo(id: number) {
  return useQuery({
    queryKey: clavesClientes.tipo(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/tipos/${id}`, { schema: TipoRespuesta, signal }).then((r) => r.tipo),
    enabled: id > 0,
  })
}

export type CuerpoTipo = { nombre?: string; secciones?: Record<string, boolean>; rapido?: boolean }

export function useCrearTipo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: CuerpoTipo) => api('/api/v1/clientes/tipos', { method: 'POST', body: d, schema: TipoCreadoRespuesta }),
    onSuccess: () => qc.invalidateQueries({ queryKey: clavesClientes.tipos }),
  })
}

export function useGuardarTipo(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: CuerpoTipo) => api(`/api/v1/clientes/tipos/${id}`, { method: 'PATCH', body: d, schema: TipoRespuesta }),
    onSuccess: () => qc.invalidateQueries({ queryKey: clavesClientes.tipos }),
  })
}

export function useBorrarTipo() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (id: number) => api(`/api/v1/clientes/tipos/${id}`, { method: 'DELETE', schema: VacioRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesClientes.todo })
      aviso('Tipo eliminado.')
    },
    onError: (e) => aviso(mensajeError(e, 'No se ha podido eliminar el tipo.'), { tipo: 'error' }),
  })
}

/* ---------- Servicios ---------- */

export function useServicios() {
  return useQuery({
    queryKey: clavesClientes.servicios,
    queryFn: ({ signal }) => api('/api/v1/clientes/servicios', { schema: ServiciosRespuesta, signal }),
  })
}

export function useGuardarServicios() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (servicios: { nombre: string; desc: string; orig: string }[]) =>
      api('/api/v1/clientes/servicios', { method: 'PATCH', body: { servicios }, schema: ServiciosGuardadosRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesClientes.servicios, { servicios: r.servicios, abiertos: r.abiertos, total: r.total })
    },
  })
}

/* ---------- Agencias ---------- */

export function useAgencias() {
  return useQuery({
    queryKey: clavesClientes.agencias,
    queryFn: ({ signal }) => api('/api/v1/clientes/agencias', { schema: AgenciasRespuesta, signal }),
  })
}

export type CuerpoAgencia = Omit<Agencia, 'id' | 'uso'>

export function useGuardarAgencia() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, datos }: { id: number | null; datos: CuerpoAgencia }) =>
      id
        ? api(`/api/v1/clientes/agencias/${id}`, { method: 'PATCH', body: datos, schema: AgenciaRespuesta })
        : api('/api/v1/clientes/agencias', { method: 'POST', body: datos, schema: AgenciaRespuesta }),
    onSuccess: () => qc.invalidateQueries({ queryKey: clavesClientes.agencias }),
  })
}

export function useBorrarAgencia() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (id: number) => api(`/api/v1/clientes/agencias/${id}`, { method: 'DELETE', schema: VacioRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesClientes.agencias })
      aviso('Agencia eliminada.')
    },
    onError: (e) => aviso(mensajeError(e, 'No se ha podido eliminar la agencia.'), { tipo: 'error' }),
  })
}

export function useAsignarAgencia() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: ({ cliente, partner }: { cliente: number; partner: number | null }) =>
      api(`/api/v1/clientes/${cliente}/agencia`, { method: 'PATCH', body: { partner_id: partner }, schema: VacioRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesClientes.agencias })
      aviso('Cliente asignado')
    },
    onError: (e) => {
      void qc.invalidateQueries({ queryKey: clavesClientes.agencias })
      aviso(mensajeError(e, 'No se pudo asignar'), { tipo: 'error' })
    },
  })
}

/* ---------- Google ---------- */

export function useGoogleCliente(id: number) {
  return useQuery({
    queryKey: clavesClientes.google(id),
    queryFn: ({ signal }) => api(`/api/v1/clientes/${id}/google`, { schema: GoogleSchema, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export type CuerpoGoogle = { site: string; prop: string; eventos: { ll: string[]; wa: string[]; fo: string[] } }

export function useGuardarGoogle(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: CuerpoGoogle) => api(`/api/v1/clientes/${id}/google`, { method: 'PATCH', body: d, schema: GoogleSchema }),
    onSuccess: (r) => {
      qc.setQueryData(clavesClientes.google(id), r)
      void qc.invalidateQueries({ queryKey: clavesClientes.ficha(id) })
    },
  })
}

export function pedirEventosGa4(id: number, prop: string) {
  return api(conQuery(`/api/v1/clientes/${id}/google/eventos`, { prop }), { schema: EventosRespuesta }).then((r) => r.eventos)
}

export function useSincronizarGoogle(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api(`/api/v1/clientes/${id}/google/sync`, { method: 'POST', schema: GoogleSyncRespuesta }),
    onSuccess: (r) => {
      qc.setQueryData(clavesClientes.google(id), r)
      void qc.invalidateQueries({ queryKey: clavesClientes.ficha(id) })
    },
  })
}

/* ---------- Datos avanzados ---------- */
/* Pasan por pedir() de Equipo: conserva la `zona` del 403 «reauth», que es lo
   que necesita useReauth para pedir la contraseña y repetir la petición. */

export function cargarAvanzado(id: number, signal?: AbortSignal) {
  return pedir(`/api/v1/clientes/${id}/avanzado`, { schema: AvanzadoRespuesta, signal })
}

export function guardarAvanzado(id: number, d: Record<string, string | number>) {
  return pedir(`/api/v1/clientes/${id}/avanzado`, { method: 'PATCH', body: d, schema: AvanzadoRespuesta })
}

export function useAvanzado(id: number) {
  return useQuery({
    queryKey: clavesClientes.avanzado(id),
    queryFn: ({ signal }) => cargarAvanzado(id, signal),
    enabled: id > 0,
    // 403 «reauth» = hay que volver a poner la contraseña: no se reintenta.
    retry: false,
  })
}

export function useBloquearAvanzado() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api('/api/v1/clientes/avanzado/bloquear', { method: 'POST', schema: VacioRespuesta }),
    onSuccess: () => qc.resetQueries({ queryKey: ['clientes', 'avanzado'] }),
  })
}
