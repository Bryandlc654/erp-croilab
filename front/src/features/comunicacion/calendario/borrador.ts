import type { EventoCal } from '../schemas'
import { diasEntre, sumarDias, sumarHora } from './logicaCalendario'
import type { CambiosEvento, DatosEvento } from './mutaciones'

/* Lo que edita el modal de evento. `id` vacío = nuevo. */
export type Borrador = {
  id: string
  titulo: string
  fecha: string
  conHora: boolean
  hora: string
  hora_fin: string
  /* Días que dura de más (evento de varios días): se conservan al editar. */
  diasExtra: number
  recur: string
  invitados: string
  ubicacion: string
  descripcion: string
  recordar: string
  meet: boolean
  gemini: boolean
  notificar: boolean
  /* Al editar un evento repetido no se toca la repetición (Google no la deja en una ocurrencia). */
  recurrente: boolean
}

export type Preset = { fecha: string; hora?: string; meet?: boolean }

export function borradorNuevo(p: Preset): Borrador {
  return {
    id: '',
    titulo: '',
    fecha: p.fecha,
    conHora: !!p.hora,
    hora: p.hora ?? '09:00',
    hora_fin: p.hora ? sumarHora(p.hora, 60) : '10:00',
    diasExtra: 0,
    recur: '',
    invitados: '',
    ubicacion: '',
    descripcion: '',
    recordar: '30',
    meet: !!p.meet,
    gemini: false,
    notificar: true,
    recurrente: false,
  }
}

/* Para editar (id = el de la ocurrencia o el de la serie) o duplicar (id = ''). */
export function borradorDeEvento(ev: EventoCal, id: string): Borrador {
  return {
    id,
    titulo: ev.titulo === '(sin título)' ? '' : ev.titulo,
    fecha: ev.dia,
    conHora: !ev.todo_el_dia,
    hora: ev.todo_el_dia ? '09:00' : ev.hora,
    hora_fin: ev.todo_el_dia ? '10:00' : ev.hora_fin || sumarHora(ev.hora, 60),
    diasExtra: ev.dia_fin && ev.dia_fin > ev.dia ? diasEntre(ev.dia, ev.dia_fin) : 0,
    recur: '',
    invitados: ev.invitados.join(', '),
    ubicacion: ev.ubicacion,
    descripcion: ev.descripcion,
    recordar: id ? ev.recordar : '30',
    meet: false,
    gemini: false,
    notificar: true,
    recurrente: !!id && ev.recurrente,
  }
}

function fechas(b: Borrador) {
  return {
    fecha: b.fecha,
    hora: b.conHora ? b.hora : '',
    hora_fin: b.conHora ? b.hora_fin : '',
    fecha_fin: b.diasExtra ? sumarDias(b.fecha, b.diasExtra) : '',
  }
}

export function datosNuevo(b: Borrador): DatosEvento {
  return {
    titulo: b.titulo.trim(),
    ...fechas(b),
    invitados: b.invitados,
    ubicacion: b.ubicacion.trim(),
    descripcion: b.descripcion.trim(),
    recur: b.recur,
    recordar: b.recordar,
    meet: b.meet,
    gemini: b.meet && b.gemini,
    notificar: b.notificar,
  }
}

/* PATCH parcial: las fechas solo si cambian (en una serie, mandarlas movería
   su inicio a esta ocurrencia) y la repetición solo si se ha tocado. */
export function cambiosEdicion(b: Borrador, orig: Borrador): CambiosEvento {
  const c: CambiosEvento = {
    id: b.id,
    titulo: b.titulo.trim(),
    invitados: b.invitados,
    ubicacion: b.ubicacion.trim(),
    descripcion: b.descripcion.trim(),
    notificar: b.notificar,
  }
  const f = fechas(b)
  const fo = fechas(orig)
  if (f.fecha !== fo.fecha || f.hora !== fo.hora || f.hora_fin !== fo.hora_fin) Object.assign(c, f)
  if (b.recordar !== orig.recordar) c.recordar = b.recordar
  if (!b.recurrente && b.recur !== orig.recur) c.recur = b.recur
  return c
}
