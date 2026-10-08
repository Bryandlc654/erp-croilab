import type { ClienteFila } from '../schemas'

/* Filtros del listado de clientes (index.php): todo en el navegador y
   combinados con Y, como el antiguo. */

export type Segmento = 'alta' | 'baja' | 'todos'
export const SEGMENTOS: Segmento[] = ['alta', 'baja', 'todos']

/* `?f=` de la URL → segmento (por defecto, «En alta»). */
export function segmentoDe(f: string | null): Segmento {
  return f === 'baja' || f === 'todos' ? f : 'alta'
}

/* Tipo: '' todos · 'none' sin tipo · id del tipo. */
export type FiltroClientes = { segmento: Segmento; q: string; tipo: string }

/* Sin acentos ni mayúsculas: «clinica» encuentra «Clínica». */
export function normalizar(s: string) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}

export function cumpleSegmento(c: Pick<ClienteFila, 'activo'>, s: Segmento) {
  return s === 'todos' || (s === 'alta' ? c.activo : !c.activo)
}

export function filtrarClientes(clientes: ClienteFila[], f: FiltroClientes): ClienteFila[] {
  const q = normalizar(f.q.trim())
  return clientes.filter((c) => {
    if (!cumpleSegmento(c, f.segmento)) return false
    if (q && !normalizar(`${c.name} ${c.username}`).includes(q)) return false
    if (f.tipo === 'none') return c.tipo_id === null
    if (f.tipo !== '') return String(c.tipo_id) === f.tipo
    return true
  })
}

/* Contadores del segmentado: no dependen del buscador ni del tipo. */
export function contarSegmentos(clientes: Pick<ClienteFila, 'activo'>[]): Record<Segmento, number> {
  const alta = clientes.filter((c) => c.activo).length
  return { alta, baja: clientes.length - alta, todos: clientes.length }
}

/* «1 cliente a la vista.» / «N clientes a la vista.» */
export function textoALaVista(n: number) {
  return `${n} cliente${n === 1 ? '' : 's'} a la vista.`
}

export const TITULOS_SEGMENTO: Record<Segmento, string> = {
  alta: 'Clientes en alta',
  baja: 'Clientes no activos',
  todos: 'Todos los clientes',
}
