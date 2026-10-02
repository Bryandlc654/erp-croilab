import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, conQuery } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { useToast } from '../../shared/ui/useToast'
import { clavesNav } from '../nav/api'
import { clavesClientes } from '../clientes/api'
import { quitarTarea, reemplazarTarea, type TareasCache } from './cache'
import {
  BorrarRespuesta,
  RestaurarRespuesta,
  TareaDetalleRespuesta,
  TareaRespuesta,
  TareasPagina,
  type CambiosTarea,
  type Tarea,
} from './schemas'

export type Vista = 'all' | 'mine' | 'emp' | 'cliente'
export type FiltrosTareas = { view: Vista; emp: number; cli: number; list: number; fe: string; fr: string }

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
  }
}

export const clavesTareas = {
  todo: ['tareas'] as const,
  listas: () => ['tareas', 'lista'] as const,
  lista: (p: ReturnType<typeof parametrosTareas>) => ['tareas', 'lista', p] as const,
  detalle: (id: number) => ['tareas', 'detalle', id] as const,
}

/* Tareas de una vista, de 100 en 100 («Cargar más»). Al cambiar de filtro se
   sigue viendo la vista anterior hasta que llega la nueva, como antes. */
export function useTareas(f: FiltrosTareas) {
  const p = parametrosTareas(f)
  return useInfiniteQuery({
    queryKey: clavesTareas.lista(p),
    queryFn: ({ pageParam, signal }) => api(conQuery('/api/v1/tareas', { ...p, limit: LIMITE, offset: pageParam }), { schema: TareasPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    placeholderData: keepPreviousData,
  })
}

export function useTarea(id: number) {
  return useQuery({
    queryKey: clavesTareas.detalle(id),
    queryFn: ({ signal }) => api(`/api/v1/tareas/${id}`, { schema: TareaDetalleRespuesta, signal }),
  })
}

function mensaje(e: unknown, porDefecto: string) {
  return e instanceof ApiError ? e.message : porDefecto
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
export function useBorrarTarea() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const restaurar = useRestaurarTarea()
  return useMutation({
    mutationFn: (t: Tarea) => api(`/api/v1/tareas/${t.id}`, { method: 'DELETE', schema: BorrarRespuesta }),
    onMutate: async (t) => {
      await qc.cancelQueries({ queryKey: clavesTareas.listas() })
      qc.setQueriesData<TareasCache>({ queryKey: clavesTareas.listas() }, (d) => quitarTarea(d, t.id))
    },
    onSuccess: ({ papelera_id }) => {
      void qc.invalidateQueries({ queryKey: clavesNav.nav })
      void qc.invalidateQueries({ queryKey: clavesClientes.todo })
      aviso('Tarea eliminada', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
    },
    onError: (e) => aviso(mensaje(e, 'No se ha podido borrar la tarea.'), { tipo: 'error' }),
    // Se recargan las listas: si falló vuelve la tarea, y si no, los totales y
    // los offsets de «Cargar más» cuadran con el servidor.
    onSettled: () => qc.invalidateQueries({ queryKey: clavesTareas.listas() }),
  })
}
