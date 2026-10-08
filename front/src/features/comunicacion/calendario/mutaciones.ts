import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, conQuery } from '../../../shared/api/client'
import { clavesCom } from '../api'
import { EventoRespuesta, MsgRespuesta, type CalendarioDatos } from '../schemas'

/* Escrituras del calendario (POST/PATCH/DELETE /v1/calendario/eventos). Tras
   cada una se refrescan el calendario y las reuniones (un evento con
   invitados o Meet también sale en Reuniones). */

export type DatosEvento = {
  titulo: string
  fecha: string
  hora: string
  hora_fin: string
  fecha_fin?: string
  invitados: string
  ubicacion: string
  descripcion: string
  recur?: string
  recordar?: string
  meet?: boolean
  gemini?: boolean
  notificar: boolean
}

export type CambiosEvento = Partial<DatosEvento> & { id: string }
/* `origen` (dónde estaba la ocurrencia) solo al mover «toda la serie»: el
   servidor desplaza la serie lo mismo, en vez de hacerla empezar aquí. */
export type Movimiento = { id: string; fecha: string; hora: string; hora_fin: string; fecha_fin: string; origen?: { fecha: string; hora: string; hora_fin: string; fecha_fin: string } }

function useInvalidar() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: clavesCom.calendarioTodo })
    void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })
  }
}

export function useCrearEvento() {
  const invalidar = useInvalidar()
  return useMutation({
    mutationFn: (d: DatosEvento) => api('/api/v1/calendario/eventos', { method: 'POST', body: d, schema: EventoRespuesta }),
    onSuccess: invalidar,
  })
}

export function useEditarEvento() {
  const invalidar = useInvalidar()
  return useMutation({
    mutationFn: (d: CambiosEvento) => api('/api/v1/calendario/eventos', { method: 'PATCH', body: d, schema: EventoRespuesta }),
    onSuccess: invalidar,
  })
}

export function useBorrarEvento() {
  const invalidar = useInvalidar()
  return useMutation({
    mutationFn: (id: string) => api(conQuery('/api/v1/calendario/eventos', { id, avisar: 1 }), { method: 'DELETE', schema: MsgRespuesta }),
    onSuccess: invalidar,
  })
}

/* Mover/redimensionar: se pinta ya en su sitio nuevo (sin esperar a Google)
   y se deshace si la API falla. `local` = el id del evento que se ve (para
   «toda la serie» el PATCH va a la serie, pero lo que se arrastró es este). */
export function useMoverEvento() {
  const qc = useQueryClient()
  const invalidar = useInvalidar()
  return useMutation({
    mutationFn: ({ mov }: { mov: Movimiento; local: string }) =>
      api('/api/v1/calendario/eventos', { method: 'PATCH', body: { ...mov, solo_fechas: true }, schema: EventoRespuesta }),
    onMutate: async ({ mov, local }) => {
      await qc.cancelQueries({ queryKey: clavesCom.calendarioTodo })
      const antes = qc.getQueriesData<CalendarioDatos>({ queryKey: clavesCom.calendarioTodo })
      qc.setQueriesData<CalendarioDatos>({ queryKey: clavesCom.calendarioTodo }, (d) =>
        d
          ? {
              ...d,
              eventos: d.eventos.map((e) =>
                e.id === local && e.mio ? { ...e, dia: mov.fecha, dia_fin: mov.fecha_fin || mov.fecha, hora: mov.hora, hora_fin: mov.hora_fin, todo_el_dia: mov.hora === '' } : e,
              ),
            }
          : d,
      )
      return { antes }
    },
    onError: (_e, _v, ctx) => {
      for (const [k, v] of ctx?.antes ?? []) qc.setQueryData(k, v)
    },
    onSettled: invalidar,
  })
}
