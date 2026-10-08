export type DirOrden = 'asc' | 'desc'
export type Orden = { key: string; dir: DirOrden } | null
export type ValorOrden = string | number | boolean | Date | null | undefined

const comparador = new Intl.Collator('es', { numeric: true, sensitivity: 'base' })

function comparar(a: ValorOrden, b: ValorOrden) {
  // Los vacíos siempre al final, se ordene como se ordene.
  const va = a === null || a === undefined || a === ''
  const vb = b === null || b === undefined || b === ''
  if (va || vb) return va === vb ? 0 : va ? 1 : -1
  if (a instanceof Date && b instanceof Date) return a.getTime() - b.getTime()
  if (typeof a === 'number' && typeof b === 'number') return a - b
  if (typeof a === 'boolean' && typeof b === 'boolean') return Number(a) - Number(b)
  return comparador.compare(String(a), String(b))
}

/* Orden estable de filas por el valor que da `valor(fila)`. */
export function ordenarFilas<T>(filas: T[], valor: ((f: T) => ValorOrden) | undefined, dir: DirOrden): T[] {
  if (!valor) return filas
  const signo = dir === 'asc' ? 1 : -1
  return filas
    .map((f, i) => ({ f, i, v: valor(f) }))
    .sort((x, y) => {
      const c = comparar(x.v, y.v)
      // Vacíos al final también en descendente: no se invierte su posición.
      const vacio = x.v === null || x.v === undefined || x.v === '' || y.v === null || y.v === undefined || y.v === ''
      return (vacio ? c : c * signo) || x.i - y.i
    })
    .map((x) => x.f)
}

/* Clic en una cabecera: ascendente → descendente → sin orden. */
export function siguienteOrden(actual: Orden, key: string): Orden {
  if (!actual || actual.key !== key) return { key, dir: 'asc' }
  if (actual.dir === 'asc') return { key, dir: 'desc' }
  return null
}

/* Estado de la casilla «todos» según cuántas filas visibles están marcadas. */
export function estadoSeleccion<K>(visibles: K[], marcadas: ReadonlySet<K>): 'none' | 'some' | 'all' {
  if (visibles.length === 0) return 'none'
  const n = visibles.filter((k) => marcadas.has(k)).length
  return n === 0 ? 'none' : n === visibles.length ? 'all' : 'some'
}
