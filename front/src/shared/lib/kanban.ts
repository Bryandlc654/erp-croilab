/* Movimientos puros del tablero kanban (embudo del CRM, negocio.php). */

export type IdKanban = string | number
export type PosKanban = { col: number; idx: number }

/* Dónde está una tarjeta: índice de columna y posición dentro de ella. */
export function ubicar<T>(columnas: readonly { items: readonly T[] }[], getId: (t: T) => IdKanban, id: IdKanban): PosKanban | null {
  for (let c = 0; c < columnas.length; c++) {
    const idx = columnas[c].items.findIndex((t) => getId(t) === id)
    if (idx >= 0) return { col: c, idx }
  }
  return null
}

/* Mueve la tarjeta `id` a la columna `aCol`, posición `indice` (contada en la
   columna destino SIN la tarjeta). Devuelve columnas nuevas; las que no
   cambian conservan su objeto. */
export function moverTarjeta<T, C extends { id: IdKanban; items: readonly T[] }>(columnas: readonly C[], getId: (t: T) => IdKanban, id: IdKanban, aCol: IdKanban, indice: number): C[] {
  const desde = ubicar(columnas, getId, id)
  const destino = columnas.findIndex((c) => c.id === aCol)
  if (!desde || destino < 0) return [...columnas]
  const tarjeta = columnas[desde.col].items[desde.idx]
  return columnas.map((c, k) => {
    if (k !== desde.col && k !== destino) return c
    const items = c.items.filter((t) => getId(t) !== id)
    if (k === destino) items.splice(Math.max(0, Math.min(indice, items.length)), 0, tarjeta)
    return { ...c, items }
  })
}

/* ¿Cambia algo soltar `id` en (aCol, indice)? */
export function esMovimiento<T>(columnas: readonly { id: IdKanban; items: readonly T[] }[], getId: (t: T) => IdKanban, id: IdKanban, aCol: IdKanban, indice: number) {
  const desde = ubicar(columnas, getId, id)
  if (!desde) return false
  return columnas[desde.col].id !== aCol || desde.idx !== indice
}

/* Teclado (tarjeta «cogida» con Espacio): ←/→ cambia de columna conservando
   la altura si cabe; ↑/↓ sube o baja dentro de la columna. */
export function pasoTeclado(longitudes: readonly number[], pos: PosKanban, tecla: string): PosKanban {
  const { col, idx } = pos
  if (tecla === 'ArrowLeft' || tecla === 'ArrowRight') {
    const c = col + (tecla === 'ArrowLeft' ? -1 : 1)
    if (c < 0 || c >= longitudes.length) return pos
    // En otra columna la tarjeta aún no cuenta: puede ir de 0 a su longitud.
    return { col: c, idx: Math.min(idx, longitudes[c]) }
  }
  if (tecla === 'ArrowUp') return { col, idx: Math.max(0, idx - 1) }
  if (tecla === 'ArrowDown') return { col, idx: Math.min(Math.max(0, longitudes[col] - 1), idx + 1) }
  return pos
}

/* Columna bajo el puntero (por su franja horizontal). */
export function columnaEnX(cajas: readonly { left: number; width: number }[], x: number): number {
  for (let k = 0; k < cajas.length; k++) if (x >= cajas[k].left && x < cajas[k].left + cajas[k].width) return k
  // Entre columnas o fuera: la más cercana.
  let mejor = -1
  let dist = Infinity
  cajas.forEach((c, k) => {
    const d = Math.min(Math.abs(x - c.left), Math.abs(x - (c.left + c.width)))
    if (d < dist) {
      dist = d
      mejor = k
    }
  })
  return mejor
}
