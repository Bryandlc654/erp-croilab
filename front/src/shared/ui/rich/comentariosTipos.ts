import type { ReactNode } from 'react'
import type { Adjunto } from '../../lib/adjuntos'
import type { Reaccion } from './ReactionChips'

export type IdComentario = number | string

export type AutorComentario = { id: number | string; username: string; foto?: string | null }

/* Un comentario tal como lo pinta CommentThread (lo que devuelva la API se
   adapta a esto en el módulo). `cuerpo` va en el formato del ERP. */
export type Comentario = {
  id: IdComentario
  /* null = «Sistema». */
  autor: AutorComentario | null
  cuerpo: string
  /* 'AAAA-MM-DD HH:MM:SS' o ISO. */
  creado: string
  editado?: boolean
  /* Respuesta a otro comentario (reply_to). */
  replyTo?: IdComentario | null
  reacciones?: Reaccion[]
  /* Las imágenes rellenan los [[img]] del cuerpo en orden; el resto va debajo. */
  adjuntos?: Adjunto[]
}

/* Línea de sistema intercalada por fecha («Tarea creada», «Estado → Completada»). */
export type EventoActividad = { id: IdComentario; at: string; texto: ReactNode; actor?: string | null }

/* Lo que entrega el compositor al enviar. */
export type EnvioComentario = { cuerpo: string; archivos: File[]; replyTo: IdComentario | null }
