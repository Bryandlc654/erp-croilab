import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient, type InfiniteData, type QueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { api, ApiError, AUTH_EXPIRED, conQuery, CSRF_EXPIRED, leerCsrf, url } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { useToast } from '../../shared/ui/useToast'
import { clavesNav } from '../nav/api'
import { leerImporte, type Filtros } from './logica'
import {
  AdjuntosRespuesta,
  BandejaSchema,
  CatalogosSchema,
  ContactoRespuesta,
  ContactosPagina,
  ConversionRespuesta,
  CsvRespuesta,
  DashboardSchema,
  EtiquetaCreadaRespuesta,
  EtiquetasRespuesta,
  FacturacionRespuesta,
  FaseBorradaRespuesta,
  FasesRespuesta,
  FichaSchema,
  GenerarRespuesta,
  ImportacionSchema,
  ListaRespuesta,
  ListasRespuesta,
  LoteRespuesta,
  NegocioRespuesta,
  NegociosRespuesta,
  PapeleraRespuesta,
  PropuestasRespuesta,
  RestauradoRespuesta,
  ResumenEjecutadoRespuesta,
  ResumenSchema,
  VacioRespuesta,
  VistasRespuesta,
  type Catalogos,
  type Contacto,
  type Ficha,
  type Negocio,
  type PaginaContactos,
} from './schemas'

const B = '/api/v1/crm'

export const clavesCrm = {
  todo: ['crm'] as const,
  catalogos: ['crm', 'catalogos'] as const,
  contactos: ['crm', 'contactos'] as const,
  lista: (f: Partial<Filtros>) => ['crm', 'contactos', f] as const,
  ficha: (id: number) => ['crm', 'ficha', id] as const,
  negocios: (arch: boolean) => ['crm', 'negocios', arch] as const,
  listas: ['crm', 'listas'] as const,
  listaDetalle: (id: number) => ['crm', 'lista', id] as const,
  vistas: ['crm', 'vistas'] as const,
  dashboard: (d: string, h: string) => ['crm', 'dashboard', d, h] as const,
  seguimientos: ['crm', 'seguimientos'] as const,
  resumen: ['crm', 'resumen'] as const,
}

export function mensajeError(e: unknown, porDefecto = 'No se ha podido guardar.') {
  return e instanceof ApiError ? e.message : porDefecto
}

/* Subidas multipart (adjuntos, CSV): api() solo habla JSON. Mismo manejo de
   CSRF, sesión y errores. */
export async function subir<S extends z.ZodType>(path: string, form: FormData, schema: S): Promise<z.infer<S>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const t = leerCsrf()
  if (t) headers['X-CSRF-Token'] = t
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
  if (!res.success) throw new ApiError(r.status, `La respuesta del servidor no tiene el formato esperado (POST ${path}).`, 'contrato')
  return res.data
}

/* ---------- Catálogos ---------- */

export function useCatalogos() {
  return useQuery({
    queryKey: clavesCrm.catalogos,
    queryFn: ({ signal }) => api(`${B}/catalogos`, { schema: CatalogosSchema, signal }),
    staleTime: 60_000,
  })
}

/* ---------- Contactos ---------- */

const POR_PAGINA = 300

export function useContactos(f: Partial<Filtros>) {
  return useInfiniteQuery({
    queryKey: clavesCrm.lista(f),
    queryFn: ({ pageParam, signal }) => api(conQuery(`${B}/contactos`, { ...f, limit: POR_PAGINA, offset: pageParam }), { schema: ContactosPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    placeholderData: keepPreviousData,
  })
}

/* Cambia un contacto en todas las listas cargadas (edición optimista y respuesta del servidor). */
function ponerEnListas(qc: QueryClient, id: number, cambio: (c: Contacto) => Contacto) {
  qc.setQueriesData<InfiniteData<PaginaContactos>>({ queryKey: clavesCrm.contactos }, (d) =>
    d ? { ...d, pages: d.pages.map((p) => ({ ...p, items: p.items.map((c) => (c.id === id ? cambio(c) : c)) })) } : d,
  )
  qc.setQueryData<Ficha>(clavesCrm.ficha(id), (d) => (d ? { ...d, contacto: cambio(d.contacto) } : d))
}

export type CambioContacto = Partial<Record<'nombre' | 'empresa' | 'sector' | 'email' | 'telefono' | 'whatsapp' | 'linkedin' | 'web' | 'origen_lead' | 'proxima_accion', string>> & {
  valor?: string | number | null
  fecha_prox?: string | null
  fecha_ultimo_contacto?: string | null
  propietario_id?: number | null
  servicios?: string[]
  fase?: string
}

/* Guardado en línea: se ve al momento y, si el servidor lo rechaza, vuelve a
   lo que había con «No se pudo guardar…» (cmSave del antiguo). */
export function useActualizarContacto() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: ({ id, cambio }: { id: number; cambio: CambioContacto }) => api(`${B}/contactos/${id}`, { method: 'PATCH', body: cambio, schema: ContactoRespuesta }),
    onMutate: async ({ id, cambio }) => {
      await qc.cancelQueries({ queryKey: clavesCrm.contactos })
      const antes = qc.getQueriesData<InfiniteData<PaginaContactos>>({ queryKey: clavesCrm.contactos })
      const fichaAntes = qc.getQueryData<Ficha>(clavesCrm.ficha(id))
      ponerEnListas(qc, id, (c) => {
        const n = { ...c }
        for (const [k, v] of Object.entries(cambio)) {
          if (k === 'valor') n.valor = v === null || v === '' ? null : typeof v === 'number' ? v : (leerImporte(String(v)) ?? c.valor)
          else (n as Record<string, unknown>)[k] = v ?? (k === 'propietario_id' || k.startsWith('fecha') ? null : '')
        }
        return n
      })
      return { antes, fichaAntes }
    },
    onError: (e, { id, cambio }, ctx) => {
      ctx?.antes.forEach(([k, d]) => qc.setQueryData(k, d))
      if (ctx?.fichaAntes) qc.setQueryData(clavesCrm.ficha(id), ctx.fichaAntes)
      const campo = 'fase' in cambio ? 'la fase vuelve a la anterior' : 'el campo vuelve a su valor anterior'
      aviso(`No se pudo guardar: ${campo}. ${mensajeError(e, '')}`.trim(), { tipo: 'error' })
    },
    onSuccess: (r, { id, cambio }) => {
      ponerEnListas(qc, id, () => r.contacto)
      aviso('Guardado')
      if ('fase' in cambio || 'propietario_id' in cambio) void qc.invalidateQueries({ queryKey: clavesCrm.ficha(id) })
    },
  })
}

export function useCrearContacto() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: CambioContacto & { nombre: string }) => api(`${B}/contactos`, { method: 'POST', body: d, schema: ContactoRespuesta }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: clavesCrm.contactos }),
  })
}

export function useRestaurar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (papeleraId: number) => api(`${B}/papelera/${papeleraId}/restaurar`, { method: 'POST', schema: RestauradoRespuesta }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: clavesCrm.todo }),
  })
}

/* Borrar a la papelera con «Deshacer» en el aviso (7 s). */
export function useBorrarContacto() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const restaurar = useRestaurar()
  return useMutation({
    mutationFn: (c: { id: number; nombre: string }) => api(`${B}/contactos/${c.id}`, { method: 'DELETE', schema: PapeleraRespuesta }),
    onSuccess: (r, c) => {
      void qc.invalidateQueries({ queryKey: clavesCrm.todo })
      aviso(`Contacto «${c.nombre}» eliminado`, { accion: { label: 'Deshacer', fn: () => restaurar.mutate(r.papelera_id, { onSuccess: () => aviso('Contacto restaurado') }) } })
    },
    onError: (e) => aviso(mensajeError(e, 'No se ha podido eliminar.'), { tipo: 'error' }),
  })
}

export type OpLote =
  | { op: 'asignar'; propietario_id: number | null }
  | { op: 'etiquetar'; tag_id: number }
  | { op: 'a_lista'; list_id: number }
  | { op: 'nueva_lista'; nombre: string }
  | { op: 'borrar' }

export function useLote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ ids, accion }: { ids: number[]; accion: OpLote }) => api(`${B}/contactos/lote`, { method: 'POST', body: { ids, ...accion }, schema: LoteRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
      void qc.invalidateQueries({ queryKey: clavesCrm.listas })
    },
  })
}

export async function exportarContactos(params: Record<string, string>) {
  return api(conQuery(`${B}/contactos/exportar`, params), { schema: CsvRespuesta })
}

export async function plantillaCsv() {
  return api(`${B}/contactos/plantilla`, { schema: CsvRespuesta })
}

export async function importarCsv(archivo: File, opciones: { prueba: boolean; mapeo?: string[]; omitirDuplicados?: boolean }) {
  const f = new FormData()
  f.append('csv', archivo)
  f.append('prueba', opciones.prueba ? '1' : '0')
  if (opciones.mapeo) f.append('mapeo', JSON.stringify(Object.fromEntries(opciones.mapeo.map((c, i) => [i, c]))))
  if (opciones.omitirDuplicados) f.append('omitir_duplicados', '1')
  return subir(`${B}/contactos/importar`, f, ImportacionSchema)
}

/* ---------- Ficha ---------- */

export function useFicha(id: number) {
  return useQuery({
    queryKey: clavesCrm.ficha(id),
    queryFn: ({ signal }) => api(`${B}/contactos/${id}`, { schema: FichaSchema, signal }),
    enabled: id > 0,
  })
}

/* Acciones de la ficha que devuelven la ficha entera o una parte. */
export function useAccionFicha(id: number) {
  const qc = useQueryClient()
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesCrm.ficha(id) })
    void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
  }
  const poner = (f: Ficha) => {
    qc.setQueryData(clavesCrm.ficha(id), f)
    ponerEnListas(qc, id, () => f.contacto)
  }
  return {
    comentar: useMutation({
      mutationFn: (d: { tipo: string; contenido: string }) => api(`${B}/contactos/${id}/comentarios`, { method: 'POST', body: d, schema: FichaSchema }),
      onSuccess: poner,
    }),
    borrarComentario: useMutation({
      mutationFn: (cid: number) => api(`${B}/comentarios/${cid}`, { method: 'DELETE', schema: FichaSchema }),
      onSuccess: poner,
    }),
    actividad: useMutation({
      mutationFn: (d: { tipo: string; descripcion: string }) => api(`${B}/contactos/${id}/actividad`, { method: 'POST', body: d, schema: VacioRespuesta }),
      onSuccess: refrescar,
    }),
    facturacion: useMutation({
      mutationFn: (d: Record<string, string>) => api(`${B}/contactos/${id}/facturacion`, { method: 'PATCH', body: d, schema: FacturacionRespuesta }),
      onSuccess: (r) => qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, facturacion: r.facturacion } : f)),
    }),
    etiqueta: useMutation({
      mutationFn: ({ tag, on }: { tag: number; on: boolean }) => api(`${B}/contactos/${id}/etiquetas/${tag}`, { method: on ? 'POST' : 'DELETE', schema: ContactoRespuesta }),
      onSuccess: (r) => ponerEnListas(qc, id, () => r.contacto),
    }),
    crearPropuesta: useMutation({
      mutationFn: (d: { nombre: string; importe?: string; estado?: string; fecha_envio?: string; url_archivo?: string }) =>
        api(`${B}/contactos/${id}/propuestas`, { method: 'POST', body: d, schema: PropuestasRespuesta }),
      onSuccess: (r) => {
        qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, propuestas: r.propuestas } : f))
        refrescar()
      },
    }),
    editarPropuesta: useMutation({
      mutationFn: ({ pid, ...d }: { pid: number; estado?: string; url_archivo?: string; nombre?: string; importe?: string }) =>
        api(`${B}/propuestas/${pid}`, { method: 'PATCH', body: d, schema: PropuestasRespuesta }),
      onSuccess: (r) => qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, propuestas: r.propuestas } : f)),
    }),
    borrarPropuesta: useMutation({
      mutationFn: (pid: number) => api(`${B}/propuestas/${pid}`, { method: 'DELETE', schema: PropuestasRespuesta }),
      onSuccess: (r) => qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, propuestas: r.propuestas } : f)),
    }),
    subirAdjunto: useMutation({
      mutationFn: (archivo: File) => {
        const f = new FormData()
        f.append('archivo', archivo)
        return subir(`${B}/contactos/${id}/adjuntos`, f, AdjuntosRespuesta)
      },
      onSuccess: (r) => {
        qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, adjuntos: r.adjuntos } : f))
        refrescar()
      },
    }),
    borrarAdjunto: useMutation({
      mutationFn: (aid: number) => api(`${B}/adjuntos/${aid}`, { method: 'DELETE', schema: AdjuntosRespuesta }),
      onSuccess: (r) => qc.setQueryData<Ficha>(clavesCrm.ficha(id), (f) => (f ? { ...f, adjuntos: r.adjuntos } : f)),
    }),
  }
}

/* Lead → cliente (desde la ficha o desde un negocio). */
export function useConvertir() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (d: { contacto?: number; negocio?: number }) =>
      api(d.negocio ? `${B}/negocios/${d.negocio}/convertir` : `${B}/contactos/${d.contacto}/convertir`, { method: 'POST', schema: ConversionRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesCrm.todo })
      void qc.invalidateQueries({ queryKey: ['clientes'] })
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
    },
  })
}

/* ---------- Negocios ---------- */

export function useNegocios(archivados: boolean) {
  return useQuery({
    queryKey: clavesCrm.negocios(archivados),
    queryFn: ({ signal }) => api(conQuery(`${B}/negocios`, { archivados: archivados ? 1 : undefined }), { schema: NegociosRespuesta, signal }),
  })
}

function ponerNegocio(qc: QueryClient, n: Negocio) {
  for (const arch of [false, true]) {
    qc.setQueryData<z.infer<typeof NegociosRespuesta>>(clavesCrm.negocios(arch), (d) =>
      d ? { ...d, items: n.archivado === arch ? (d.items.some((x) => x.id === n.id) ? d.items.map((x) => (x.id === n.id ? n : x)) : [n, ...d.items]) : d.items.filter((x) => x.id !== n.id) } : d,
    )
  }
}

export function useAccionNegocio() {
  const qc = useQueryClient()
  const despues = (r: { negocio: Negocio }) => {
    ponerNegocio(qc, r.negocio)
    /* Métricas, fase del contacto y seguimientos dependen del negocio. */
    void qc.invalidateQueries({ queryKey: ['crm', 'negocios'] })
    void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
    void qc.invalidateQueries({ queryKey: clavesCrm.ficha(r.negocio.contact_id) })
  }
  return {
    crear: useMutation({
      mutationFn: (d: { contact_id: number; nombre?: string; valor?: string; fecha_cierre_prevista?: string | null }) =>
        api(`${B}/negocios`, { method: 'POST', body: d, schema: NegocioRespuesta }),
      onSuccess: despues,
    }),
    editar: useMutation({
      mutationFn: ({ id, ...d }: { id: number; nombre?: string; valor?: string | null; servicio?: string; fecha_cierre_prevista?: string | null; propietario_id?: number | null; archivado?: boolean }) =>
        api(`${B}/negocios/${id}`, { method: 'PATCH', body: d, schema: NegocioRespuesta }),
      onSuccess: despues,
    }),
    mover: useMutation({
      mutationFn: ({ id, ...d }: { id: number; fase: string; indice?: number; motivo?: string; comentario?: string }) =>
        api(`${B}/negocios/${id}/mover`, { method: 'POST', body: d, schema: NegocioRespuesta }),
      onMutate: async ({ id, fase }) => {
        await qc.cancelQueries({ queryKey: ['crm', 'negocios'] })
        const antes = qc.getQueryData<z.infer<typeof NegociosRespuesta>>(clavesCrm.negocios(false))
        qc.setQueryData<z.infer<typeof NegociosRespuesta>>(clavesCrm.negocios(false), (d) => (d ? { ...d, items: d.items.map((n) => (n.id === id ? { ...n, fase } : n)) } : d))
        return { antes }
      },
      onError: (_e, _v, ctx) => {
        if (ctx?.antes) qc.setQueryData(clavesCrm.negocios(false), ctx.antes)
      },
      onSuccess: despues,
    }),
    perder: useMutation({
      mutationFn: ({ id, ...d }: { id: number; motivo: string; comentario: string }) => api(`${B}/negocios/${id}/perder`, { method: 'POST', body: d, schema: NegocioRespuesta }),
      onSuccess: despues,
    }),
    etiqueta: useMutation({
      mutationFn: ({ id, tag, on }: { id: number; tag: number; on: boolean }) => api(`${B}/negocios/${id}/etiquetas/${tag}`, { method: on ? 'POST' : 'DELETE', schema: NegocioRespuesta }),
      onSuccess: despues,
    }),
    borrar: useMutation({
      mutationFn: (id: number) => api(`${B}/negocios/${id}`, { method: 'DELETE', schema: PapeleraRespuesta }),
      onSuccess: () => void qc.invalidateQueries({ queryKey: clavesCrm.todo }),
    }),
  }
}

/* ---------- Configuración: fases, etiquetas, vistas ---------- */

function ponerCatalogo(qc: QueryClient, cambio: (c: Catalogos) => Catalogos) {
  qc.setQueryData<Catalogos>(clavesCrm.catalogos, (c) => (c ? cambio(c) : c))
}

export function useFases() {
  const qc = useQueryClient()
  const poner = (r: { fases: Catalogos['fases'] }) => {
    ponerCatalogo(qc, (c) => ({ ...c, fases: r.fases }))
    void qc.invalidateQueries({ queryKey: ['crm', 'negocios'] })
  }
  return {
    crear: useMutation({ mutationFn: (d: { nombre: string; probabilidad?: number; color?: string }) => api(`${B}/fases`, { method: 'POST', body: d, schema: FasesRespuesta }), onSuccess: poner }),
    editar: useMutation({
      mutationFn: ({ id, ...d }: { id: number; nombre?: string; probabilidad?: number; color?: string }) => api(`${B}/fases/${id}`, { method: 'PATCH', body: d, schema: FasesRespuesta }),
      onSuccess: poner,
    }),
    orden: useMutation({ mutationFn: (ids: number[]) => api(`${B}/fases/orden`, { method: 'POST', body: { ids }, schema: FasesRespuesta }), onSuccess: poner }),
    borrar: useMutation({ mutationFn: (id: number) => api(`${B}/fases/${id}`, { method: 'DELETE', schema: FaseBorradaRespuesta }), onSuccess: poner }),
  }
}

export function useEtiquetas() {
  const qc = useQueryClient()
  const poner = (r: { etiquetas: Catalogos['etiquetas'] }) => {
    ponerCatalogo(qc, (c) => ({ ...c, etiquetas: r.etiquetas }))
    void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
  }
  return {
    crear: useMutation({ mutationFn: (d: { nombre: string; color: string }) => api(`${B}/etiquetas`, { method: 'POST', body: d, schema: EtiquetaCreadaRespuesta }), onSuccess: poner }),
    editar: useMutation({
      mutationFn: ({ id, ...d }: { id: number; nombre?: string; color?: string }) => api(`${B}/etiquetas/${id}`, { method: 'PATCH', body: d, schema: EtiquetasRespuesta }),
      onSuccess: poner,
    }),
    borrar: useMutation({ mutationFn: (id: number) => api(`${B}/etiquetas/${id}`, { method: 'DELETE', schema: EtiquetasRespuesta }), onSuccess: poner }),
  }
}

export function useVistas() {
  return useQuery({ queryKey: clavesCrm.vistas, queryFn: ({ signal }) => api(`${B}/vistas`, { schema: VistasRespuesta, signal }), select: (d) => d.vistas })
}

export function useAccionVistas() {
  const qc = useQueryClient()
  const poner = (r: z.infer<typeof VistasRespuesta>) => qc.setQueryData(clavesCrm.vistas, r)
  return {
    crear: useMutation({ mutationFn: (d: { nombre: string; filtros: Record<string, string> }) => api(`${B}/vistas`, { method: 'POST', body: d, schema: VistasRespuesta }), onSuccess: poner }),
    borrar: useMutation({ mutationFn: (id: number) => api(`${B}/vistas/${id}`, { method: 'DELETE', schema: VistasRespuesta }), onSuccess: poner }),
  }
}

/* ---------- Listas ---------- */

export function useListas(enabled = true) {
  return useQuery({ queryKey: clavesCrm.listas, queryFn: ({ signal }) => api(`${B}/listas`, { schema: ListasRespuesta, signal }), select: (d) => d.listas, enabled })
}

export function useLista(id: number) {
  return useQuery({ queryKey: clavesCrm.listaDetalle(id), queryFn: ({ signal }) => api(`${B}/listas/${id}`, { schema: ListaRespuesta, signal }), enabled: id > 0 })
}

export function useAccionListas() {
  const qc = useQueryClient()
  const poner = (r: z.infer<typeof ListaRespuesta>) => {
    qc.setQueryData(clavesCrm.listaDetalle(r.lista.id), r)
    void qc.invalidateQueries({ queryKey: clavesCrm.listas })
  }
  return {
    crear: useMutation({
      mutationFn: (d: { nombre: string; descripcion?: string; tipo: string; condiciones?: Record<string, string>; ids?: number[] }) =>
        api(`${B}/listas`, { method: 'POST', body: d, schema: ListaRespuesta }),
      onSuccess: poner,
    }),
    renombrar: useMutation({
      mutationFn: ({ id, ...d }: { id: number; nombre?: string; descripcion?: string }) => api(`${B}/listas/${id}`, { method: 'PATCH', body: d, schema: ListaRespuesta }),
      onSuccess: poner,
    }),
    congelar: useMutation({ mutationFn: (id: number) => api(`${B}/listas/${id}/congelar`, { method: 'POST', schema: ListaRespuesta }), onSuccess: poner }),
    miembro: useMutation({
      mutationFn: ({ id, contacto, on }: { id: number; contacto: number; on: boolean }) =>
        api(`${B}/listas/${id}/miembros/${contacto}`, { method: on ? 'POST' : 'DELETE', schema: ListaRespuesta }),
      onSuccess: (r) => {
        poner(r)
        void qc.invalidateQueries({ queryKey: ['crm', 'ficha'] })
      },
    }),
    borrar: useMutation({
      mutationFn: (id: number) => api(`${B}/listas/${id}`, { method: 'DELETE', schema: VacioRespuesta }),
      onSuccess: (_r, id) => {
        qc.removeQueries({ queryKey: clavesCrm.listaDetalle(id) })
        void qc.invalidateQueries({ queryKey: clavesCrm.listas })
      },
    }),
    orden: useMutation({
      mutationFn: (ids: number[]) => api(`${B}/listas/orden`, { method: 'POST', body: { ids }, schema: ListasRespuesta }),
      onMutate: (ids) => {
        qc.setQueryData<z.infer<typeof ListasRespuesta>>(clavesCrm.listas, (d) => (d ? { listas: ids.map((i) => d.listas.find((l) => l.id === i)).filter((l) => l !== undefined) } : d))
      },
      onSuccess: (r) => qc.setQueryData(clavesCrm.listas, r),
    }),
  }
}

export async function exportarLista(id: number) {
  return api(`${B}/listas/${id}/exportar`, { schema: CsvRespuesta })
}

/* ---------- Dashboard y seguimientos ---------- */

export function useDashboard(desde: string, hasta: string) {
  return useQuery({
    queryKey: clavesCrm.dashboard(desde, hasta),
    queryFn: ({ signal }) => api(conQuery(`${B}/dashboard`, { desde, hasta }), { schema: DashboardSchema, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useSeguimientos() {
  return useQuery({ queryKey: clavesCrm.seguimientos, queryFn: ({ signal }) => api(`${B}/seguimientos`, { schema: BandejaSchema, signal }) })
}

export function useResumen(enabled: boolean) {
  return useQuery({ queryKey: clavesCrm.resumen, queryFn: ({ signal }) => api(`${B}/resumen-diario`, { schema: ResumenSchema, signal }), enabled })
}

export function useAccionSeguimientos() {
  const qc = useQueryClient()
  const poner = (r: z.infer<typeof BandejaSchema>) => {
    qc.setQueryData(clavesCrm.seguimientos, BandejaSchema.parse(r))
    void qc.invalidateQueries({ queryKey: clavesCrm.resumen })
  }
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesCrm.seguimientos })
    void qc.invalidateQueries({ queryKey: clavesCrm.resumen })
    void qc.invalidateQueries({ queryKey: clavesCrm.contactos })
  }
  return {
    accion: useMutation({
      mutationFn: ({ id, que, dias }: { id: number; que: 'hecho' | 'posponer' | 'omitir'; dias?: number }) =>
        api(`${B}/seguimientos/${id}/${que}`, { method: 'POST', body: que === 'posponer' ? { dias } : undefined, schema: VacioRespuesta }),
      onSuccess: refrescar,
    }),
    crear: useMutation({
      mutationFn: (d: { contact_id: number; canal: string; descripcion: string; fecha: string }) => api(`${B}/seguimientos`, { method: 'POST', body: d, schema: BandejaSchema }),
      onSuccess: poner,
    }),
    generar: useMutation({ mutationFn: () => api(`${B}/seguimientos/generar`, { method: 'POST', schema: GenerarRespuesta }), onSuccess: poner }),
    resumen: useMutation({ mutationFn: () => api(`${B}/resumen-diario/ejecutar`, { method: 'POST', schema: ResumenEjecutadoRespuesta }), onSuccess: poner }),
  }
}
