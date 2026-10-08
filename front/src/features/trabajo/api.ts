import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, conQuery } from '../../shared/api/client'
import { siguienteOffset } from '../../shared/schemas'
import { useToast } from '../../shared/ui/useToast'
import { clavesNav } from '../nav/api'
import { clavesTareas } from '../tareas/api'
import { clavesClientes } from '../clientes/api'
import {
  AccionAvisosRespuesta,
  ActaRespuesta,
  ActasPagina,
  AgendaSchema,
  AvisosPagina,
  BusquedaSchema,
  InicioSchema,
  PapeleraIdRespuesta,
  PapeleraPagina,
  RestaurarPapeleraRespuesta,
  SondeoSchema,
  VaciarRespuesta,
  Vacio,
  type Bandeja,
} from './schemas'

export const clavesTrabajo = {
  inicio: ['trabajo', 'inicio'] as const,
  agenda: ['trabajo', 'agenda'] as const,
  avisos: ['trabajo', 'avisos'] as const,
  bandeja: (b: Bandeja) => ['trabajo', 'avisos', b] as const,
  sondeo: ['trabajo', 'sondeo'] as const,
  buscar: (q: string, n: number) => ['trabajo', 'buscar', q, n] as const,
  papelera: ['trabajo', 'papelera'] as const,
  actas: ['trabajo', 'actas'] as const,
  listaActas: (q: string, autor: number) => ['trabajo', 'actas', 'lista', q, autor] as const,
  acta: (id: number) => ['trabajo', 'actas', id] as const,
}

export function mensaje(e: unknown, porDefecto: string) {
  return e instanceof ApiError ? e.message : porDefecto
}

/* ---------- Inicio ---------- */

export function useInicio() {
  return useQuery({ queryKey: clavesTrabajo.inicio, queryFn: ({ signal }) => api('/api/v1/inicio', { schema: InicioSchema, signal }) })
}

/* Google Calendar va aparte: el panel no espera a Google. */
export function useAgenda() {
  return useQuery({ queryKey: clavesTrabajo.agenda, queryFn: ({ signal }) => api('/api/v1/inicio/agenda', { schema: AgendaSchema, signal }), staleTime: 5 * 60_000 })
}

/* ---------- Avisos ---------- */

export function useBandeja(b: Bandeja) {
  return useInfiniteQuery({
    queryKey: clavesTrabajo.bandeja(b),
    queryFn: ({ pageParam, signal }) => api(conQuery('/api/v1/notificaciones', { bandeja: b, limit: 100, offset: pageParam }), { schema: AvisosPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
    placeholderData: keepPreviousData,
  })
}

/* Sondeo de avisos nuevos (globo y pop-ups). `despues` = último id ya visto. */
export function pedirSondeo(despues: number, signal?: AbortSignal) {
  return api(conQuery('/api/v1/notificaciones/avisos', { despues }), { schema: SondeoSchema, signal })
}

export type AccionAviso = 'leer' | 'no_leer' | 'posponer' | 'traer' | 'borrar' | 'restaurar' | 'purgar'

export function useAccionesAvisos() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesTrabajo.avisos })
    void qc.invalidateQueries({ queryKey: clavesNav.nav })
  }
  const error = (e: unknown) => aviso(mensaje(e, 'No se ha podido hacer.'), { tipo: 'error' })
  return {
    accion: useMutation({
      mutationFn: (b: { accion: AccionAviso; ids: number[]; horas?: number }) => api('/api/v1/notificaciones/acciones', { method: 'POST', body: b, schema: AccionAvisosRespuesta }),
      onSettled: refrescar,
      onError: error,
    }),
    leerTodas: useMutation({
      mutationFn: () => api('/api/v1/notificaciones/leer-todas', { method: 'POST', schema: AccionAvisosRespuesta }),
      onSuccess: () => aviso('Todas marcadas como leídas'),
      onSettled: refrescar,
      onError: error,
    }),
    leidasAPapelera: useMutation({
      mutationFn: () => api('/api/v1/notificaciones/leidas-a-papelera', { method: 'POST', schema: AccionAvisosRespuesta }),
      onSuccess: () => aviso('Leídas enviadas a la papelera'),
      onSettled: refrescar,
      onError: error,
    }),
    vaciar: useMutation({
      mutationFn: () => api('/api/v1/notificaciones/vaciar-papelera', { method: 'POST', schema: AccionAvisosRespuesta }),
      onSuccess: () => aviso('Papelera vaciada'),
      onSettled: refrescar,
      onError: error,
    }),
  }
}

/* ---------- Búsqueda ---------- */

export function buscar(q: string, porGrupo: number, signal?: AbortSignal) {
  return api(conQuery('/api/v1/buscar', { q, por_grupo: porGrupo }), { schema: BusquedaSchema, signal })
}

export function useBusqueda(q: string) {
  const texto = q.trim()
  return useQuery({
    queryKey: clavesTrabajo.buscar(texto, 40),
    queryFn: ({ signal }) => buscar(texto, 40, signal),
    enabled: texto.length >= 2,
    placeholderData: keepPreviousData,
  })
}

/* ---------- Papelera ---------- */

export function usePapelera() {
  return useInfiniteQuery({
    queryKey: clavesTrabajo.papelera,
    queryFn: ({ pageParam, signal }) => api(conQuery('/api/v1/papelera', { limit: 100, offset: pageParam }), { schema: PapeleraPagina, signal }),
    initialPageParam: 0,
    getNextPageParam: siguienteOffset,
  })
}

export function useAccionesPapelera() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const refrescar = () => {
    void qc.invalidateQueries({ queryKey: clavesTrabajo.papelera })
    // Lo restaurado puede ser de cualquier módulo: se refresca lo que pinta Trabajo.
    void qc.invalidateQueries({ queryKey: clavesTareas.todo })
    void qc.invalidateQueries({ queryKey: clavesClientes.todo })
    void qc.invalidateQueries({ queryKey: clavesTrabajo.actas })
    void qc.invalidateQueries({ queryKey: clavesNav.nav })
  }
  const error = (porDefecto: string) => (e: unknown) => aviso(mensaje(e, porDefecto), { tipo: 'error' })
  return {
    restaurar: useMutation({
      mutationFn: (id: number) => api(`/api/v1/papelera/${id}/restaurar`, { method: 'POST', schema: RestaurarPapeleraRespuesta }),
      onSettled: refrescar,
      onError: error('No se ha podido restaurar.'),
    }),
    purgar: useMutation({
      mutationFn: (id: number) => api(`/api/v1/papelera/${id}`, { method: 'DELETE', schema: Vacio }),
      onSuccess: () => aviso('Eliminado definitivamente.'),
      onSettled: refrescar,
      onError: error('No se ha podido eliminar.'),
    }),
    vaciar: useMutation({
      mutationFn: () => api('/api/v1/papelera', { method: 'DELETE', schema: VaciarRespuesta }),
      onSuccess: () => aviso('Papelera vaciada.'),
      onSettled: refrescar,
      onError: error('No se ha podido vaciar la papelera.'),
    }),
  }
}

/* ---------- Actas ---------- */

export function useActas(q: string, autor: number) {
  return useQuery({
    queryKey: clavesTrabajo.listaActas(q, autor),
    queryFn: ({ signal }) => api(conQuery('/api/v1/actas', { q: q || undefined, autor: autor || undefined, limit: 300 }), { schema: ActasPagina, signal }),
    placeholderData: keepPreviousData,
  })
}

export function useActa(id: number) {
  return useQuery({
    queryKey: clavesTrabajo.acta(id),
    queryFn: ({ signal }) => api(`/api/v1/actas/${id}`, { schema: ActaRespuesta, signal }),
    enabled: id > 0,
    retry: (n, e) => !(e instanceof ApiError && e.status === 404) && n < 2,
  })
}

export function useAccionesActas() {
  const qc = useQueryClient()
  const { aviso } = useToast()
  const refrescar = () => void qc.invalidateQueries({ queryKey: clavesTrabajo.actas })
  const error = (porDefecto: string) => (e: unknown) => aviso(mensaje(e, porDefecto), { tipo: 'error' })
  const restaurar = useMutation({
    mutationFn: (id: number) => api(`/api/v1/papelera/${id}/restaurar`, { method: 'POST', schema: RestaurarPapeleraRespuesta }),
    onSuccess: refrescar,
    onError: error('No se ha podido deshacer.'),
  })
  return {
    crear: useMutation({
      mutationFn: (b: { titulo: string; contenido: string }) => api('/api/v1/actas', { method: 'POST', body: b, schema: ActaRespuesta }),
      onSuccess: (r) => {
        qc.setQueryData(clavesTrabajo.acta(r.acta.id), r)
        refrescar()
      },
      onError: error('No se ha podido guardar el acta.'),
    }),
    guardar: useMutation({
      mutationFn: ({ id, ...b }: { id: number; titulo: string; contenido: string; version: string }) => api(`/api/v1/actas/${id}`, { method: 'PATCH', body: b, schema: ActaRespuesta }),
      onSuccess: (r) => {
        qc.setQueryData(clavesTrabajo.acta(r.acta.id), r)
        refrescar()
      },
      onError: error('No se ha podido guardar el acta.'),
    }),
    fijar: useMutation({
      mutationFn: ({ id, fijada }: { id: number; fijada: boolean }) => api(`/api/v1/actas/${id}/fijar`, { method: 'POST', body: { fijada }, schema: ActaRespuesta }),
      onSuccess: (r) => {
        qc.setQueryData(clavesTrabajo.acta(r.acta.id), r)
        refrescar()
        aviso(r.acta.fijada ? 'Acta fijada' : 'Acta desfijada')
      },
      onError: error('No se ha podido fijar.'),
    }),
    borrar: useMutation({
      mutationFn: (id: number) => api(`/api/v1/actas/${id}`, { method: 'DELETE', schema: PapeleraIdRespuesta }),
      onSuccess: ({ papelera_id }) => {
        refrescar()
        aviso('Acta eliminada', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(papelera_id) } })
      },
      onError: error('No se ha podido borrar el acta.'),
    }),
  }
}
