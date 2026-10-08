import { mesNombre } from '../../shared/lib/formato'
import type { Tarea } from './schemas'

/* «Octubre 2026»: el mes que se propone en una lista de informes vacía
   (mismo texto libre que guardaba el antiguo en tasks.mes). */
export function mesActual(hoy: Date = new Date()) {
  return `${mesNombre(hoy.getMonth() + 1)} ${hoy.getFullYear()}`
}

/* Orden de todas las tareas de la lista tras arrastrar dentro de un grupo de
   estado: los demás grupos se quedan como estaban y el movido entra en su
   sitio. `grupos` son los ids visibles de cada grupo, en el orden de pantalla. */
export function ordenTrasMover(grupos: number[][], indiceGrupo: number, nuevos: number[]): number[] {
  return grupos.flatMap((g, i) => (i === indiceGrupo ? nuevos : g))
}

/* Cambios que se mandan al marcar una tarea como completada desde el menú. */
export function completar(t: Pick<Tarea, 'estado'>) {
  return t.estado === 'completada' ? null : ({ estado: 'completada' } as const)
}
