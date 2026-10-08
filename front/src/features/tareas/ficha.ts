import { fechaCorta } from '../../shared/lib/formato'
import type { Comentario, EventoActividad } from '../../shared/ui/rich'
import type { Actividad, ComentarioTarea } from './schemas'

/* Lógica pura de la ficha de la tarea (sin React): textos del historial,
   horas, anclas de la URL y adaptación de los comentarios al kit. */

/* Texto de cada línea del historial («cambió el estado a En proceso»). */
export function textoEvento(a: Pick<Actividad, 'tipo' | 'detalle'>): string {
  const d = a.detalle.trim()
  switch (a.tipo) {
    case 'creada':
      return 'creó la tarea'
    case 'estado':
      return `cambió el estado a ${d}`
    case 'prioridad':
      return d === 'Sin prioridad' ? 'quitó la prioridad' : `puso prioridad ${d}`
    case 'asignados':
      return d ? `asignó la tarea a ${d}` : 'quitó a todos los asignados'
    case 'due_date':
      return d ? `puso la fecha límite el ${fechaCorta(d)}` : 'quitó la fecha límite'
    case 'fecha_inicio':
      return d ? `puso la fecha de inicio el ${fechaCorta(d)}` : 'quitó la fecha de inicio'
    case 'titulo':
      return `renombró la tarea a «${d}»`
    case 'adjunto':
      return `adjuntó ${d}`
    case 'tiempo':
      return `apuntó horas · ${d}`
    default:
      return d ? `${a.tipo} · ${d}` : a.tipo
  }
}

/* Eventos del panel. Las tareas antiguas no tienen historial: como en el
   ERP, al menos «Tarea creada» con su fecha. */
export function eventosDeTarea(actividad: Actividad[], creada: string): EventoActividad[] {
  const ev: EventoActividad[] = actividad.map((a) => ({ id: `a${a.id}`, at: a.created_at, texto: textoEvento(a), actor: a.actor }))
  if (!actividad.some((a) => a.tipo === 'creada') && creada) ev.unshift({ id: 'creada', at: creada, texto: 'Tarea creada', actor: null })
  return ev
}

/* 90 → «1,5 h»; 0 → «0 h». */
export function horasTexto(min: number) {
  const h = Math.round((min / 60) * 100) / 100
  return `${String(h).replace('.', ',')} h`
}

/* «1,5» / «1.5» / «» → texto que acepta la API, o null si no es un número. */
export function horasValidas(s: string): string | null {
  const t = s.trim().replace(',', '.')
  if (t === '') return ''
  return /^\d+(\.\d+)?$/.test(t) ? t : null
}

/* «#c123» → 123 (comentario enlazado desde un aviso). */
export function comentarioDelHash(hash: string): number | null {
  const m = /^#c(\d+)$/.exec(hash)
  return m ? Number(m[1]) : null
}

/* Un comentario de la API, en la forma que pinta CommentThread. */
export function comentarioAKit(c: ComentarioTarea, url: (relativa: string) => string): Comentario {
  return {
    id: c.id,
    autor: c.autor,
    cuerpo: c.cuerpo,
    creado: c.created_at,
    editado: c.editado,
    replyTo: c.reply_to,
    reacciones: c.reacciones,
    adjuntos: c.adjuntos.map((a) => ({ id: a.id, nombre: a.nombre, url: url(a.url), mime: a.mime || null })),
  }
}
