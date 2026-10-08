import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, conQuery } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { useToast } from '../../shared/ui/useToast'
import { clavesNav } from '../nav/api'
import { clavesClientes } from '../clientes/api'
import { quitarTarea, reemplazarTarea, type TareasCache } from './cache'
import { enviarFormulario } from './enviar'
import {
  AdjuntosRespuesta,
  ArchivosRespuesta,
  BorrarRespuesta,
  ChecklistRespuesta,
  ComentarioRespuesta,
  ComentariosRespuesta,
  InformeRespuesta,
  ListaRespuesta,
  ListasRespuesta,
  MesesRespuesta,
  PuntosComentarioRespuesta,
  ReaccionesRespuesta,
  RestaurarRespuesta,
  TareaDetalleRespuesta,
  TareaRespuesta,
  TareasPagina,
  TiempoRespuesta,
  VacioRespuesta,
  type CambiosTarea,
  type PuntoComentario,
  type Tarea,
  type TareaDetalle,
} from './schemas'

export type Vista = 'all' | 'mine' | 'emp' | 'cliente'
export type FiltrosTareas = { view: Vista; emp: number; cli: number; list: number; fe: string; fr: string; mes?: string }

const LIMITE = 100

/* Solo los parámetros que la API usa en cada vista: así dos URL equivalentes
   comparten la misma entrada de caché. */
export function parametrosTareas(f: FiltrosTareas) {
  return {
    view: f.view,
    emp: f.view === 'emp' && f.emp ? f.emp : undefined,
    cli: f.view === 'cliente' && f.cli ? f.cli : undefined,
    list: f.view === 'cliente' && f.list ? f.list : undefined,
    fe: f.fe || undefined,
    fr: f.view === 'all' && f.fr ? f.fr : undefined,
    mes: f.view === 'cliente' && f.mes ? f.mes : undefined,
  }
}

export const clavesTareas = {
  todo: ['tareas'] as const,
  listas: () => ['tareas', 'lista'] as const,
  lista: (p: ReturnType<typeof parametrosTareas>) => ['tareas', 'lista', p] as const,
  detalle: (id: number) => ['tareas', 'detalle', id] as const,
  comentarios: (id: number) => ['tareas', 'comentarios', id] as const,
  meses: (cli: number, list: number) => ['tareas', 'meses', cli, list] as const,
  informe: (list: number, mes: string) => ['tareas', 'informe', list, mes] as const,
  listasCliente: (cli: number) => ['tareas', 'listas-cliente', cli] as const,
}

export function mensaje(e: unknown, porDefecto: string) {
  return e instanceof ApiError ? e.message : porDefecto
}

/* Tareas de una vista, de 100 en 100 («Cargar más»). Al cambiar de filtro se
   sigue viendo la vista anterior hasta que llega la nueva, como antes. */
export function useTareas(f: FiltrosTareas, enabled = true) {
  const p = parametrosTareas(f)
  return useInfiniteQuery({
    queryKey: clavesTareas.lista(p),
    queryFn: ({ pageParam, signal }) => api(conQuery('/api/v1/tareas', { ...p, limit: LIMITE, offset: pageParam }), { schema: TareasPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useTarea(id: number) {
  return useQuery({
    queryKey: clavesTareas.detalle(id),
    queryFn: ({ signal }) => api(`/api/v1/tareas/${id}`, { schema: TareaDetalleRespuesta, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && (e.status === 404 || e.status === 403)) && n < 2,
  })
}

/* Feed de comentarios de la ficha. Se refresca cada 5 s solo con la pestaña
   visible (TanStack no sondea en segundo plano): sustituye al antiguo, que
   volvía a pintar la página entera cada 5 s. */
export function useComentarios(id: number) {
  return useQuery({
    queryKey: clavesTareas.comentarios(id),
    queryFn: ({ signal }) => api(`/api/v1/tareas/${id}/comentarios`, { schema: ComentariosRespuesta, signal }),
    enabled: id > 0,
    refetchInterval: 5000,
  })
}

type VarsActualizar = {
  /* Versión que había antes del cambio: es la que vuelve si la API lo rechaza. */
  antes: Tarea
  /* Cómo debe verse ya mismo, antes de que conteste el servidor. */
  optimista: Tarea
  cambios: CambiosTarea
}

/* Cambio en línea: se ve al momento en todas las listas en caché y, si la API
   lo rechaza, se deshace solo esa tarea (no se pisan otros cambios en vuelo). */
export function useActualizarTarea() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: ({ antes, cambios }: VarsActualizar) => api(`/api/v1/tareas/${antes.id}`, { method: 'PATCH', body: cambios, schema: TareaRespuesta }),
    onMutate: async ({ optimista }) => {
      // Una carga en curso traería la versión vieja y taparía el cambio.
      await qc.cancelQueries({ queryKey: clavesTareas.listas() })
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => reemplazarTarea(d, optimista.id, optimista))
    },
    onSuccess: ({ tarea }) => {
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => reemplazarTarea(d, tarea.id, tarea))
    },
    onError: (e, { antes }) => {
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => reemplazarTarea(d, antes.id, antes))
      aviso(mensaje(e, 'No se ha podido guardar el cambio.'), { tipo: 'error' })
    },
    onSettled: (_d, _e, { antes, cambios }) => {
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      void qc.invalidateQueries({ queryKey: clavesTareas.detalle(antes.id) })
      // Las tareas pendientes de cada lista (barra lateral) solo cambian con el estado.
      if ('estado' in cambios) void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    },
  })
}

/* Cambio desde la ficha: la ficha cambia al momento (optimista) y, al
   contestar el servidor, se refrescan las listas del tablero. */
export function useGuardarFicha(id: number, onGuardado?: () => void) {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (cambios: CambiosTarea) => api(`/api/v1/tareas/${id}`, { method: 'PATCH', body: cambios, schema: TareaRespuesta }),
    onMutate: async (cambios) => {
      await qc.cancelQueries({ queryKey: clavesTareas.detalle(id) })
      const antes = qc.getQueryData<{ tarea: TareaDetalle }>(clavesTareas.detalle(id))
      if (antes) {
        // Los asignados llegan como ids: la ficha se pinta con lo que devuelva la API.
        const resto: Partial<TareaDetalle> = Object.fromEntries(Object.entries(cambios).filter(([k]) => k !== 'asignados'))
        qc.setQueryData(clavesTareas.detalle(id), { tarea: { ...antes.tarea, ...resto } })
      }
      return { antes }
    },
    onSuccess: ({ tarea }) => {
      const actual = qc.getQueryData<{ tarea: TareaDetalle }>(clavesTareas.detalle(id))
      if (actual) qc.setQueryData(clavesTareas.detalle(id), { tarea: { ...actual.tarea, ...tarea } })
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => reemplazarTarea(d, tarea.id, tarea))
      onGuardado?.()
    },
    onError: (e, _v, ctx) => {
      if (ctx?.antes) qc.setQueryData(clavesTareas.detalle(id), ctx.antes)
      aviso(e instanceof ApiError && e.status === 403 ? 'Tu cuenta es de solo lectura: este cambio no se guarda.' : mensaje(e, 'No se ha podido guardar. Recarga la página.'), { tipo: 'error' })
    },
    onSettled: (_d, _e, cambios) => {
      void qc.invalidateQueries({ queryKey: clavesTareas.listas() })
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      // El historial de la ficha cambia con casi todo; la descripción no lo toca.
      if (!('descripcion' in cambios && Object.keys(cambios).length === 1)) void qc.invalidateQueries({ queryKey: clavesTareas.detalle(id) })
      if ('estado' in cambios) void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    },
  })
}

export type NuevaTarea = { client_id: number; list_id: number; titulo: string } & CambiosTarea

export function useCrearTarea() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (t: NuevaTarea) => api('/api/v1/tareas', { method: 'POST', body: t, schema: TareaRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesTareas.todo })
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    },
    onError: (e) => aviso(mensaje(e, 'No se ha podido crear la tarea.'), { tipo: 'error' }),
  })
}

/* Recupera de la papelera lo que se acaba de borrar («Deshacer»). */
export function useRestaurarTarea() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (papeleraId: number) => api(`/api/v1/papelera/${papeleraId}/restaurar`, { method: 'POST', schema: RestaurarRespuesta }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: clavesTareas.todo })
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    },
    onError: (e) => aviso(mensaje(e, 'No se ha podido deshacer.'), { tipo: 'error' }),
  })
}

/* Borrar manda la tarea a la papelera: desaparece al momento y el aviso
   ofrece «Deshacer» durante unos segundos. */
export function useBorrarTarea(onBorrada?: () => void) {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const restaurar = useRestaurarTarea()
  return useMutation({
    mutationFn: (t: Pick<Tarea, 'id'>) => api(`/api/v1/tareas/${t.id}`, { method: 'DELETE', schema: BorrarRespuesta }),
    onMutate: async (t) => {
      await qc.cancelQueries({ queryKey: clavesTareas.listas() })
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => quitarTarea(d, t.id))
    },
    onSuccess: ({ papelera_id }) => {
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      void qc.invalidateQueries({ queryKey: clavesClientes.todo })
      aviso('Tarea eliminada', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
      onBorrada?.()
    },
    onError: (e) => aviso(mensaje(e, 'No se ha podido borrar la tarea.'), { tipo: 'error' }),
    // Se recargan las listas: si falló vuelve la tarea, y si no, los totales y
    // los offsets de «Cargar más» cuadran con el servidor.
    onSettled: () => qc.invalidateQueries({ queryKey: clavesTareas.listas() }),
  })
}

/* Orden nuevo de las tareas de una lista (arrastrar en el tablero). */
export function useReordenarTareas() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: ({ list_id, ids }: { list_id: number; ids: number[] }) => api('/api/v1/tareas/orden', { method: 'POST', body: { list_id, ids }, schema: VacioRespuesta }),
    onError: (e) => aviso(mensaje(e, 'No se ha podido guardar el orden.'), { tipo: 'error' }),
    onSettled: () => qc.invalidateQueries({ queryKey: clavesTareas.listas() }),
  })
}

/* ---------- Ficha: checklist, adjuntos, horas ---------- */

/* Cada acción de la ficha devuelve su trozo actualizado y se mete en la caché de la ficha. */
function usePonerEnFicha(id: number) {
  const qc = useQueryClient()
  return (parte: Partial<TareaDetalle>) => {
    const d = qc.getQueryData<{ tarea: TareaDetalle }>(clavesTareas.detalle(id))
    if (d) qc.setQueryData(clavesTareas.detalle(id), { tarea: { ...d.tarea, ...parte } })
  }
}

export function useChecklist(id: number) {
  const poner = usePonerEnFicha(id)
  const qc = useQueryClient()
  const { aviso } = useToast()
  const comun = {
    onSuccess: ({ checklist }: { checklist: TareaDetalle['checklist'] }) => poner({ checklist }),
    onError: (e: unknown) => {
      aviso(mensaje(e, 'No se ha podido guardar la lista de control.'), { tipo: 'error' })
      void qc.invalidateQueries({ queryKey: clavesTareas.detalle(id) })
    },
  }
  return {
    crear: useMutation({ mutationFn: (b: { texto: string; asignados: number[] }) => api(`/api/v1/tareas/${id}/checklist`, { method: 'POST', body: b, schema: ChecklistRespuesta }), ...comun }),
    cambiar: useMutation({
      mutationFn: ({ chk, ...b }: { chk: number; done?: boolean; texto?: string; asignados?: number[] }) =>
        api(`/api/v1/tareas/${id}/checklist/${chk}`, { method: 'PATCH', body: b, schema: ChecklistRespuesta }),
      ...comun,
    }),
    borrar: useMutation({ mutationFn: (chk: number) => api(`/api/v1/tareas/${id}/checklist/${chk}`, { method: 'DELETE', schema: ChecklistRespuesta }), ...comun }),
    ordenar: useMutation({ mutationFn: (ids: number[]) => api(`/api/v1/tareas/${id}/checklist/orden`, { method: 'POST', body: { ids }, schema: ChecklistRespuesta }), ...comun }),
  }
}

export function useAdjuntos(id: number) {
  const poner = usePonerEnFicha(id)
  const qc = useQueryClient()
  const { aviso } = useToast()
  return {
    subir: useMutation({
      mutationFn: (files: File[]) => {
        const fd = new FormData()
        for (const f of files) fd.append('archivos[]', f)
        return enviarFormulario(`/api/v1/tareas/${id}/adjuntos`, fd, AdjuntosRespuesta)
      },
      onSuccess: ({ adjuntos }, files) => {
        poner({ adjuntos })
        void qc.invalidateQueries({ queryKey: clavesTareas.detalle(id) })
        aviso(files.length === 1 ? 'Archivo adjuntado' : `${files.length} archivos adjuntados`)
      },
      onError: (e) => aviso(mensaje(e, 'No se ha podido subir.'), { tipo: 'error' }),
    }),
    quitar: useMutation({
      mutationFn: (aid: number) => api(`/api/v1/tareas/${id}/adjuntos/${aid}`, { method: 'DELETE', schema: AdjuntosRespuesta }),
      onSuccess: ({ adjuntos }) => poner({ adjuntos }),
      onError: (e) => aviso(mensaje(e, 'No se ha podido quitar el adjunto.'), { tipo: 'error' }),
    }),
  }
}

/* Imagen o archivo para incrustar en la descripción ([[img:FN]] / [[file:FN|nombre]]). */
export async function subirParaDescripcion(id: number, files: File[]) {
  const fd = new FormData()
  for (const f of files) fd.append('archivos[]', f)
  const r = await enviarFormulario(`/api/v1/tareas/${id}/archivos`, fd, ArchivosRespuesta)
  return r.archivos.map((a) => ({ fn: a.fn, nombre: a.nombre, imagen: a.imagen }))
}

export function useTiempo(id: number) {
  const poner = usePonerEnFicha(id)
  const qc = useQueryClient()
  const { aviso } = useToast()
  return useMutation({
    mutationFn: (b: { horas: string; admin_id: number }) => api(`/api/v1/tareas/${id}/tiempo`, { method: 'POST', body: b, schema: TiempoRespuesta }),
    onSuccess: ({ tiempo }) => {
      poner({ tiempo })
      void qc.invalidateQueries({ queryKey: clavesTareas.detalle(id) })
    },
    onError: (e) => aviso(mensaje(e, 'No se han podido guardar las horas.'), { tipo: 'error' }),
  })
}

/* ---------- Ficha: comentarios ---------- */

export function useAccionesComentarios(id: number) {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesTareas.comentarios(id) })
  }
  const error = (porDefecto: string) => (e: unknown) => aviso(mensaje(e, porDefecto), { tipo: 'error' })
  return {
    enviar: useMutation({
      mutationFn: (b: { cuerpo: string; archivos: File[]; replyTo: number | null; checklist: PuntoComentario[] }) => {
        const fd = new FormData()
        fd.append('cuerpo', b.cuerpo)
        if (b.replyTo) fd.append('reply_to', String(b.replyTo))
        if (b.checklist.length) fd.append('checklist', JSON.stringify(b.checklist))
        for (const f of b.archivos) fd.append('archivos[]', f)
        return enviarFormulario(`/api/v1/tareas/${id}/comentarios`, fd, ComentarioRespuesta)
      },
      onSuccess: refrescar,
      onError: error('No se ha podido enviar el comentario.'),
    }),
    editar: useMutation({
      mutationFn: ({ cid, ...b }: { cid: number; cuerpo?: string; checklist?: PuntoComentario[] }) =>
        api(`/api/v1/tareas/${id}/comentarios/${cid}`, { method: 'PATCH', body: b, schema: ComentarioRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido guardar el comentario.'),
    }),
    borrar: useMutation({
      mutationFn: (cid: number) => api(`/api/v1/tareas/${id}/comentarios/${cid}`, { method: 'DELETE', schema: VacioRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido borrar el comentario.'),
    }),
    reaccionar: useMutation({
      mutationFn: ({ cid, emoji }: { cid: number; emoji: string }) => api(`/api/v1/tareas/${id}/comentarios/${cid}/reacciones`, { method: 'POST', body: { emoji }, schema: ReaccionesRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido reaccionar.'),
    }),
    marcarPunto: useMutation({
      mutationFn: ({ cid, idx, done }: { cid: number; idx: number; done: boolean }) =>
        api(`/api/v1/tareas/${id}/comentarios/${cid}/checklist/${idx}`, { method: 'POST', body: { done }, schema: PuntosComentarioRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido marcar el punto.'),
    }),
  }
}

/* ---------- Listas de un cliente ---------- */

/* Listas de un cliente con «visible en el portal» (pestañas del tablero). */
export function useListasCliente(cli: number) {
  return useQuery({
    queryKey: clavesTareas.listasCliente(cli),
    queryFn: ({ signal }) => api(conQuery('/api/v1/tareas/listas', { cli }), { schema: ListasRespuesta, signal }),
    enabled: cli > 0,
  })
}

export function useMeses(cli: number, list: number, enabled: boolean) {
  return useQuery({
    queryKey: clavesTareas.meses(cli, list),
    queryFn: ({ signal }) => api(conQuery('/api/v1/tareas/meses', { cli, list }), { schema: MesesRespuesta, signal }),
    enabled: enabled && cli > 0 && list > 0,
  })
}

export function useInforme(list: number, mes: string, enabled: boolean) {
  return useQuery({
    queryKey: clavesTareas.informe(list, mes),
    queryFn: ({ signal }) => api(conQuery(`/api/v1/tareas/listas/${list}/informe`, { mes }), { schema: InformeRespuesta, signal }),
    enabled: enabled && list > 0 && mes !== '',
  })
}

export function useAccionesListas() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    void qc.invalidateQueries({ queryKey: clavesTareas.todo })
    void qc.invalidateQueries({ queryKey: clavesNav.nav })
  }
  const error = (porDefecto: string) => (e: unknown) => aviso(mensaje(e, porDefecto), { tipo: 'error' })
  const restaurar = useRestaurarTarea()
  return {
    crear: useMutation({
      mutationFn: (b: { client_id: number; nombre?: string; tipo?: 'tareas' | 'informe'; por_defecto?: boolean }) => api('/api/v1/tareas/listas', { method: 'POST', body: b, schema: ListasRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido crear la lista.'),
    }),
    cambiar: useMutation({
      mutationFn: ({ id, ...b }: { id: number; nombre?: string; es_cliente?: boolean }) => api(`/api/v1/tareas/listas/${id}`, { method: 'PATCH', body: b, schema: ListaRespuesta }),
      onSuccess: refrescar,
      onError: error('No se ha podido guardar la lista.'),
    }),
    ordenar: useMutation({
      mutationFn: (b: { client_id: number; ids: number[] }) => api('/api/v1/tareas/listas/orden', { method: 'POST', body: b, schema: ListasRespuesta.pick({ listas: true }) }),
      onSuccess: refrescar,
      onError: error('No se ha podido guardar el orden.'),
    }),
    clonar: useMutation({
      mutationFn: (id: number) => api(`/api/v1/tareas/listas/${id}/clonar`, { method: 'POST', schema: ListasRespuesta }),
      onSuccess: () => {
        refrescar()
        aviso('Lista clonada con sus tareas')
      },
      onError: error('No se ha podido clonar la lista.'),
    }),
    borrar: useMutation({
      mutationFn: (l: { id: number; nombre: string }) => api(`/api/v1/tareas/listas/${l.id}`, { method: 'DELETE', schema: BorrarRespuesta }),
      onSuccess: ({ papelera_id }, l) => {
        refrescar()
        aviso(`Lista «${l.nombre}» eliminada`, { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
      },
      onError: error('No se ha podido borrar la lista.'),
    }),
    guardarInforme: useMutation({
      mutationFn: ({ list, mes, texto }: { list: number; mes: string; texto: string }) =>
        api(`/api/v1/tareas/listas/${list}/informe`, { method: 'POST', body: { mes, texto }, schema: InformeRespuesta }),
      onSuccess: (r, { list }) => {
        qc.setQueryData(clavesTareas.informe(list, r.informe.mes), r)
        void qc.invalidateQueries({ queryKey: ['tareas', 'meses'] })
        aviso('Informe guardado y publicado en el portal')
      },
      onError: error('No se ha podido guardar el informe.'),
    }),
    publicar: useMutation({
      mutationFn: (client_id: number) => api('/api/v1/tareas/publicar', { method: 'POST', body: { client_id }, schema: VacioRespuesta }),
      onSuccess: () => aviso('Progreso publicado en el portal del cliente.'),
      onError: error('No se ha podido publicar.'),
    }),
  }
}
